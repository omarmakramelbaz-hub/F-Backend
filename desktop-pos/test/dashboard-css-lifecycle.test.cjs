'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../../public/dashboard/js/dashboard-spa.js'),'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function actualBlock(start,end){const from=source.indexOf(start),to=source.indexOf(end,from);assert(from>=0&&to>from);return source.slice(from,to);}
function fixture(initial=[{href:'/assets/shared.css?v=1'}],{autoLoad=true}={}){
 const appended=[],removed=[],timers=new Map();let nextTimer=0;
 const document={baseURI:'https://dashboard.test/admin/a',head:{children:[],childNodes:[]},querySelector:()=>({}),querySelectorAll:()=>[]};
 class Style extends EventTarget{
  constructor(attributes={},tag='LINK',loaded=false){super();this.tagName=tag;this.nodeType=1;this.textContent=attributes.text||'';this.attrs=new Map(Object.entries(attributes).filter(([k])=>k!=='text'));if(tag==='LINK'&&!this.attrs.has('rel'))this.attrs.set('rel','stylesheet');this.parentNode=null;this.isConnected=false;this.sheet=loaded?{}:null;this.disabled=false;}
  get attributes(){return [...this.attrs].map(([name,value])=>({name,value}));}
  get href(){return this.hasAttribute('href')?new URL(this.getAttribute('href'),document.baseURI).href:'';}
  set href(value){this.setAttribute('href',value);}
  get nonce(){return this.attrs.get('nonce')||'';}
  getAttribute(name){return this.attrs.has(name)?this.attrs.get(name):null;}
  hasAttribute(name){return this.attrs.has(name);}
  setAttribute(name,value){this.attrs.set(name,String(value));}
  removeAttribute(name){this.attrs.delete(name);}
  matches(){return this.tagName==='STYLE'||this.tagName==='LINK'&&this.getAttribute('rel')==='stylesheet';}
  remove(){removed.push(this);if(this.parentNode){const at=this.parentNode.children.indexOf(this);if(at>=0)this.parentNode.children.splice(at,1);}this.parentNode=null;this.isConnected=false;}
  loaded(){this.sheet={};this.dispatchEvent(new Event('load'));}
  failed(){this.sheet=null;this.dispatchEvent(new Event('error'));}
 }
 document.head.insertBefore=(node,anchor)=>{assert.equal(node.parentNode,null,'Retained links must never be moved/reinserted.');const index=anchor?document.head.children.indexOf(anchor):document.head.children.length;assert(index>=0);document.head.children.splice(index,0,node);node.parentNode=document.head;node.isConnected=true;appended.push(node);if(autoLoad&&node.tagName==='LINK')queueMicrotask(()=>node.loaded());return node;};
 document.head.appendChild=node=>document.head.insertBefore(node,null);
 document.importNode=node=>new Style(Object.fromEntries(node.attrs),node.tagName,false);
 const originals=initial.map(value=>new Style(value,value.tag||'LINK',true));
 originals.forEach(node=>{node.attrs.delete('tag');document.head.children.push(node);node.parentNode=document.head;node.isConnected=true;});
 const native={add:EventTarget.prototype.addEventListener,remove:EventTarget.prototype.removeEventListener,timeout(callback){const id=++nextTimer;timers.set(id,callback);return id;},clearTimeout(id){timers.delete(id);}};
 const context=vm.createContext({document,native,URL,WeakMap,Set,Map,Array,Object,Promise,Error,pageStyles:originals,pageStyleSources:new WeakMap(),location:{href:document.baseURI}});
 vm.runInContext(actualBlock('    function pageCss(doc) {','    function inlineNames(code) {'),context);
 originals.forEach(node=>context.rememberPageStyle(node,document.baseURI));
 const cleanup=source.match(/styles\.forEach\(function \(node\) \{ if \(!styles\.reused[^\n]+\}\);/)[0];
 assert(cleanup);vm.runInContext('function discardActual(styles){'+cleanup+'}',context);
 function doc(values){const nodes=values.map(value=>new Style(value,value.tag||'LINK'));nodes.forEach(n=>n.attrs.delete('tag'));return{head:{childNodes:[{nodeType:8,textContent:'dashboard-page-css:start'},...nodes,{nodeType:8,textContent:'dashboard-page-css:end'}]},querySelectorAll:()=>[],querySelector:()=>({})};}
 const identity=()=>document.head.children.map(node=>node.getAttribute('href')||node.textContent);
 return{context,document,originals,appended,removed,timers,Style,doc,identity,stage:(values,url='https://dashboard.test/admin/b')=>context.stageCss(doc(values),url),commit:styles=>context.commitCss(styles),discard:styles=>context.discardActual(styles)};
}

test('the real SPA retains the loaded current stylesheet through repeated fresh page styles without reinserting it',async()=>{
 const f=fixture(),original=f.originals[0];
 for(let n=0;n<4;n++){const styles=await f.stage([{href:'/assets/shared.css?v=1'}]);assert.equal(styles[0],original);assert.equal(styles.reused.has(original),true);f.commit(styles);}
 assert.equal(f.appended.length,0);assert.equal(f.removed.length,0);assert.equal(original.isConnected,true);assert.equal(f.document.head.children.length,1);
});
test('full URL, version and every security or presentation attribute change obtains a new stylesheet',async()=>{
 for(const change of [{href:'/assets/shared.css?v=2'},{href:'https://foreign.test/assets/shared.css?v=1'},{media:'print'},{nonce:'new-csp'},{integrity:'sha256-new'},{crossorigin:'anonymous'},{referrerpolicy:'no-referrer'},{type:'text/css'},{title:'alternate'},{blocking:'render'},{fetchpriority:'high'},{id:'another-style'}]){
  const f=fixture([{href:'/assets/shared.css?v=1',media:'screen',nonce:'old-csp',integrity:'sha256-old',crossorigin:'use-credentials',referrerpolicy:'same-origin'}]);
  const next={href:'/assets/shared.css?v=1',media:'screen',nonce:'old-csp',integrity:'sha256-old',crossorigin:'use-credentials',referrerpolicy:'same-origin',...change};
  const styles=await f.stage([next]);assert.notEqual(styles[0],f.originals[0],JSON.stringify(change));assert.equal(f.appended.length,1);f.commit(styles);assert.equal(f.originals[0].isConnected,false);assert.equal(styles[0].getAttribute('media'),next.media);
 }
});
test('nonce hiding and differing attribute order do not discard an otherwise identical actual CSS identity',async()=>{
 const f=fixture([{href:'/assets/shared.css?v=1',nonce:'owner-nonce',media:'screen',integrity:'sha256-value'}]);
 const original=f.originals[0],get=original.getAttribute.bind(original);original.getAttribute=name=>name==='nonce'?'':get(name);
 const styles=await f.stage([{integrity:'sha256-value',media:'screen',nonce:'owner-nonce',href:'https://dashboard.test/assets/shared.css?v=1'}]);
 assert.equal(styles[0],original);assert.equal(f.appended.length,0);f.commit(styles);
});
test('relative initial href retains its actual loaded URL after history changes rather than borrowing a new resolution',async()=>{
 const f=fixture([{href:'shared.css?v=1'}]);f.document.baseURI='https://dashboard.test/admin/deeper/page';
 const same=await f.stage([{href:'https://dashboard.test/admin/shared.css?v=1'}]);assert.equal(same[0],f.originals[0]);
 const changed=await f.stage([{href:'shared.css?v=1'}],'https://dashboard.test/admin/deeper/page');assert.notEqual(changed[0],f.originals[0]);assert.equal(f.appended.length,1);
});
test('unloaded, disabled, disconnected and footer-only styles never qualify as currently active loaded CSS',async()=>{
 for(const state of ['unloaded','disabled','disconnected','footer']){
  const f=fixture(),original=f.originals[0];
  if(state==='unloaded')original.sheet=null;if(state==='disabled')original.disabled=true;if(state==='disconnected')original.isConnected=false;
  if(state==='footer'){f.document.head.children=[];original.parentNode={};}
  const styles=await f.stage([{href:'/assets/shared.css?v=1'}]);assert.notEqual(styles[0],original,state);assert.equal(f.appended.length,1);
 }
});
test('external changes after a stylesheet loaded cannot be mistaken for a freshly validated identity',async()=>{
 for(const [key,value] of [['href','/assets/changed.css'],['media','print'],['nonce','late-csp'],['integrity','sha256-changed']]){
  const f=fixture(),original=f.originals[0];original.setAttribute(key,value);
  const styles=await f.stage([{href:'/assets/shared.css?v=1',[key]:value}]);assert.notEqual(styles[0],original,key);assert.equal(f.appended.length,1);
 }
});
test('new CSS is staged around retained anchors in exact next-page cascade order without moving them',async()=>{
 const f=fixture([{href:'/a.css'},{href:'/b.css'}]),[a,b]=f.originals;
 const styles=await f.stage([{href:'/x.css'},{href:'/a.css'},{href:'/y.css'},{href:'/b.css'},{href:'/z.css'}]);
 assert.equal(f.appended.length,3);assert.equal(styles.reused.has(a),true);assert.equal(styles.reused.has(b),true);
 assert.equal(a.getAttribute('media'),null);assert.equal(b.getAttribute('media'),null);
 assert(f.appended.every(n=>n.getAttribute('media')==='not all'));
 assert.deepEqual(f.identity(),['https://dashboard.test/x.css','/a.css','https://dashboard.test/y.css','/b.css','https://dashboard.test/z.css']);
 f.commit(styles);assert.deepEqual(f.identity(),Array.from(styles,n=>n.getAttribute('href')));assert(styles.every(n=>n.getAttribute('media')===null));
});
test('reordered next-page styles reuse only an ordered subsequence and preserve the requested cascade',async()=>{
 const f=fixture([{href:'/a.css'},{href:'/b.css'}]),[a,b]=f.originals;
 const styles=await f.stage([{href:'/b.css'},{href:'/a.css'}]);f.commit(styles);
 assert.equal(styles[0],b);assert.notEqual(styles[1],a);assert.equal(a.isConnected,false);assert.deepEqual(f.identity(),['/b.css','https://dashboard.test/a.css']);assert.equal(f.appended.length,1);
});
test('a superseded slow style load discards only its newly owned nodes while the newer page keeps shared CSS',async()=>{
 const f=fixture(undefined,{autoLoad:false}),a=f.originals[0];
 const slow=f.stage([{href:'/assets/shared.css?v=1'},{href:'/slow.css'}]);await tick();const slowNode=f.appended[0];
 const newer=await f.stage([{href:'/assets/shared.css?v=1'}]);f.commit(newer);
 slowNode.loaded();const obsolete=await slow;f.discard(obsolete);
 assert.equal(a.isConnected,true);assert.equal(slowNode.isConnected,false);assert.equal(f.document.head.children.length,1);assert.equal(f.removed.includes(a),false);
});
test('a newer different page can retire a shared old style before the obsolete stage completes without touching its replacement',async()=>{
 const f=fixture(undefined,{autoLoad:false});
 const slow=f.stage([{href:'/assets/shared.css?v=1'},{href:'/slow.css'}]);await tick();const obsoleteNode=f.appended[0];
 const next=f.stage([{href:'/new-page.css'}]);await tick();const newNode=f.appended[1];newNode.loaded();const committed=await next;f.commit(committed);
 obsoleteNode.loaded();await assert.rejects(slow,/styles changed/);
 assert.equal(newNode.isConnected,true);assert.equal(obsoleteNode.isConnected,false);assert.equal(f.document.head.children.length,1);assert.equal(f.document.head.children[0],newNode);
});
test('a failed modal transition uses actual render cleanup and preserves the current reused CSS',async()=>{
 const f=fixture(),a=f.originals[0],doc=f.doc([{href:'/assets/shared.css?v=1'},{href:'/next.css'}]);
 f.context.sequence=1;f.context.closeModals=async()=>{throw Error('modal hide rejected');};
 vm.runInContext(actualBlock('    async function render(doc, url, options, current) {','    function authRedirect(response) {'),f.context);
 await assert.rejects(f.context.render(doc,'https://dashboard.test/admin/b',{},1),/modal hide rejected/);
 assert.equal(a.isConnected,true);assert.equal(f.appended[0].isConnected,false);assert.equal(f.removed.includes(a),false);assert.equal(f.document.head.children.length,1);
});
test('a style source mutation during asynchronous staging rejects before retiring the current page CSS',async()=>{
 const f=fixture(undefined,{autoLoad:false}),a=f.originals[0];
 const staging=f.stage([{href:'/assets/shared.css?v=1'},{href:'/slow.css'}]);await tick();a.setAttribute('nonce','changed-during-navigation');f.appended[0].loaded();
 await assert.rejects(staging,/styles changed/);assert.equal(a.isConnected,true);assert.equal(f.appended[0].isConnected,false);assert.equal(f.removed.includes(a),false);
});
test('invalid incoming stylesheet URL cleans partial new staging without removing shared current CSS',async()=>{
 const f=fixture(),a=f.originals[0];
 await assert.rejects(f.stage([{href:'/assets/shared.css?v=1'},{href:'/new.css'},{href:'http://[invalid'}]));
 assert.equal(a.isConnected,true);assert.equal(f.document.head.children.length,1);assert.equal(f.removed.includes(a),false);
});
test('inline styles remain fresh page nodes and are never reused merely because their text is equal',async()=>{
 const f=fixture([{tag:'STYLE',text:'body{color:red}'}]),old=f.originals[0];
 const styles=await f.stage([{tag:'STYLE',text:'body{color:red}'}]);assert.notEqual(styles[0],old);assert.equal(styles.reused.size,0);f.commit(styles);assert.equal(old.isConnected,false);
});
test('original CSS error and deadline tolerance remains while a failed stylesheet is not reused as loaded',async()=>{
 const f=fixture([],{autoLoad:false});const failed=f.stage([{href:'/unavailable.css'}]);await tick();f.appended[0].failed();const styles=await failed;f.commit(styles);
 const retry=f.stage([{href:'/unavailable.css'}]);await tick();assert.equal(f.appended.length,2);f.appended[1].loaded();await retry;
 const deadline=f.stage([{href:'/slow-deadline.css'}]);await tick();for(const callback of [...f.timers.values()])callback();await deadline;
});
