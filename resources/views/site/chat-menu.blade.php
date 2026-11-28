<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#fd7201">
<title>منيو فسخانستا</title>
<style>
:root{--brand:#fd7201;--brand-dark:#d95f00;--ink:#1d1d1f;--muted:#6b6b70;--line:#ececef;--card:#fff;--bg:#f6f6f8;--ok:#1f9d55;--err:#c62828}
*{box-sizing:border-box}html,body{margin:0}body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--ink);font-size:15px;-webkit-tap-highlight-color:transparent}
header{position:sticky;top:0;z-index:5;background:var(--brand);color:#fff;padding:12px 16px;display:flex;align-items:center;gap:10px;box-shadow:0 2px 8px rgba(0,0,0,.12)}
header img{width:36px;height:36px;border-radius:50%;background:#fff;object-fit:contain}
header h1{font-size:17px;margin:0;flex:1}header small{display:block;font-size:12px;opacity:.9;font-weight:400}
header button{background:rgba(255,255,255,.2);border:0;color:#fff;border-radius:999px;padding:6px 12px;font:inherit;font-size:13px}
main{max-width:640px;margin:0 auto;padding:12px 12px 120px}
.notice{background:var(--card);border-radius:16px;padding:28px 18px;text-align:center;margin-top:24px;line-height:1.8}
.notice .big{font-size:44px}
.branches{display:grid;gap:10px}.branch{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;text-align:start;font:inherit;color:inherit;width:100%}
.branch strong{display:block;font-size:16px}.branch span{color:var(--muted);font-size:13px}.branch[disabled]{opacity:.5}
.cats{display:flex;gap:8px;overflow-x:auto;padding:4px 0 10px;scrollbar-width:none;position:sticky;top:60px;background:var(--bg);z-index:4}
.cats::-webkit-scrollbar{display:none}
.cats button{flex:none;border:1px solid var(--line);background:var(--card);border-radius:999px;padding:7px 14px;font:inherit;font-size:13px;color:var(--ink)}
.cats button.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.search{width:100%;border:1px solid var(--line);border-radius:12px;padding:10px 12px;font:inherit;margin-bottom:8px;background:var(--card)}
.product{display:flex;gap:12px;background:var(--card);border-radius:14px;padding:10px;margin-bottom:10px;align-items:center}
.product img{width:78px;height:78px;border-radius:12px;object-fit:cover;background:#f0f0f0;flex:none}
.product .info{flex:1;min-width:0}.product h3{font-size:15px;margin:0 0 4px;line-height:1.4}
.price{color:var(--brand-dark);font-weight:700;font-size:14px}.price small{color:var(--muted);font-weight:400}
.product select{margin-top:6px;width:100%;border:1px solid var(--line);border-radius:8px;padding:5px;font:inherit;font-size:13px;background:#fff}
.qty{display:flex;align-items:center;gap:6px;margin-top:8px}
.qty button{width:34px;height:34px;border-radius:50%;border:0;font-size:20px;line-height:1;font-weight:700;display:grid;place-items:center}
.qty .plus{background:var(--brand);color:#fff}.qty .minus{background:#f1f1f3;color:var(--ink)}
.qty output{min-width:52px;text-align:center;font-weight:700}.qty .add{width:auto;height:34px;border-radius:999px;padding:0 16px;background:var(--brand);color:#fff;font-size:14px}
.bar{position:fixed;inset-inline:0;bottom:0;z-index:6;background:var(--card);border-top:1px solid var(--line);padding:10px 12px calc(10px + env(safe-area-inset-bottom));display:flex;gap:10px;align-items:center;justify-content:center}
.bar[hidden]{display:none}.bar .sum{flex:1;max-width:300px;font-size:13px;color:var(--muted)}.bar .sum b{display:block;color:var(--ink);font-size:17px}
.cta{background:var(--brand);color:#fff;border:0;border-radius:12px;padding:13px 18px;font:inherit;font-weight:700;font-size:15px;min-width:150px}
.cta[disabled]{opacity:.45}
form{background:var(--card);border-radius:16px;padding:14px}
label{display:block;font-size:13px;color:var(--muted);margin:10px 0 4px}
input,textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:11px;font:inherit;background:#fff;color:var(--ink)}
textarea{min-height:64px;resize:vertical}
.lines{list-style:none;padding:0;margin:0 0 6px}.lines li{display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px dashed var(--line);font-size:14px}
.total{display:flex;justify-content:space-between;font-weight:700;padding-top:8px}.hint{font-size:12px;color:var(--muted);margin-top:6px}
.err{color:var(--err);font-size:13px;min-height:18px;margin-top:8px}.empty{color:var(--muted);text-align:center;padding:30px 0}
@media (prefers-color-scheme:dark){:root{--ink:#f2f2f4;--muted:#a3a3aa;--line:#2c2c30;--card:#1c1c1f;--bg:#111113}input,textarea,.product select{background:#151517;color:var(--ink)}.qty .minus{background:#2a2a2e}.product img{background:#26262a}}
</style>
</head>
<body>
<header><img src="/dashboard/branding/fasakhansta-logo-transparent.png" alt=""><h1>منيو فسخانستا<small id="sub">اختار أصنافك والكميات</small></h1><button type="button" id="back" hidden>رجوع</button></header>
<main id="app">
@if ($state === 'sent')
    <div class="notice"><div class="big">✅</div><strong>طلبك اتبعت للشات</strong><p>ارجع للمحادثة، ولو البيانات تمام ابعت «موافق على الطلب».</p></div>
@elseif ($state === 'expired')
    <div class="notice"><div class="big">⌛</div><strong>الرابط ده انتهى</strong><p>اكتبلنا في الشات وهنبعتلك رابط منيو جديد.</p></div>
@endif
</main>
<div class="bar" id="bar" hidden><div class="sum"><span id="count"></span><b id="total"></b></div><button type="button" class="cta" id="next" disabled>كمّل الطلب</button></div>
@if ($state === 'open')
<script type="application/json" id="boot">@json(['branches' => $branches, 'menu' => route('chat-menu.menu', $token), 'submit' => route('chat-menu.submit', $token)])</script>
<script>
(function(){
'use strict';
var boot=JSON.parse(document.getElementById('boot').textContent), app=document.getElementById('app'), bar=document.getElementById('bar'), back=document.getElementById('back'), sub=document.getElementById('sub');
var csrf=document.querySelector('meta[name=csrf-token]').content, placeholder='/dashboard/branding/fasakhansta-logo-transparent.png';
var state={branch:null,products:[],categories:[],cat:'',q:'',cart:{},view:'branches'};
function el(tag,cls,text){var n=document.createElement(tag);if(cls)n.className=cls;if(text!=null)n.textContent=text;return n;}
function money(v){return (Math.round(v*100)/100).toLocaleString('ar-EG',{maximumFractionDigits:2})+' ج';}
function num(v){return (Math.round(v*1000)/1000).toLocaleString('ar-EG',{maximumFractionDigits:3});}
function img(u){if(!u)return placeholder;try{var x=new URL(u,location.origin);return /^https?:$/.test(x.protocol)?x.href:placeholder;}catch(e){return placeholder;}}
function product(id){return state.products.find(function(p){return p.id===id;});}
function optionsOf(p){return [{id:'',label:'الاختيار الأساسي',price:p.price}].concat(p.options||[]);}
function priceOf(p,opt){var o=optionsOf(p).find(function(x){return x.id===opt;});return o?Number(o.price):NaN;}
function step(p){return p.mode==='weight'?0.25:1;}
function unit(p){return p.mode==='weight'?'كيلو':'قطعة';}
function lines(){return Object.keys(state.cart).map(function(k){var c=state.cart[k],p=product(c.id);return p?{key:k,p:p,opt:c.opt,qty:c.qty,price:priceOf(p,c.opt)}:null;}).filter(function(l){return l&&l.qty>0&&isFinite(l.price);});}
function totals(){var ls=lines();return {count:ls.length,sum:ls.reduce(function(s,l){return s+l.price*l.qty;},0)};}
function refreshBar(){var t=totals();bar.hidden=state.view!=='menu';document.getElementById('count').textContent=t.count?t.count+' صنف':'السلة فاضية';document.getElementById('total').textContent=money(t.sum);document.getElementById('next').disabled=!t.count;}
function request(url,opts){return fetch(url,Object.assign({credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'}},opts||{})).then(function(r){return r.json().catch(function(){return {};}).then(function(d){if(!r.ok)throw new Error(d.message||'حصلت مشكلة، جرّب تاني.');return d;});});}

function showBranches(){
  state.view='branches';back.hidden=true;sub.textContent='اختار أقرب فرع ليك';app.textContent='';refreshBar();
  var list=el('div','branches');
  boot.branches.forEach(function(b){var btn=el('button','branch');btn.type='button';btn.appendChild(el('strong','',b.name));btn.appendChild(el('span','',(b.open?'':'(مقفول دلوقتي) ')+(b.address||'')));
    btn.addEventListener('click',function(){loadMenu(b);});list.appendChild(btn);});
  if(!boot.branches.length)list.appendChild(el('p','empty','مفيش فروع متاحة دلوقتي.'));
  app.appendChild(list);
  if(boot.branches.length===1)loadMenu(boot.branches[0]);
}
function loadMenu(b){
  if(state.branch&&state.branch.id!==b.id)state.cart={};
  state.branch=b;app.textContent='';app.appendChild(el('p','empty','جاري تحميل المنيو…'));
  request(boot.menu+'?branch='+b.id).then(function(d){state.products=d.products||[];state.categories=(d.categories||[]).filter(function(c){return state.products.some(function(p){return p.category_id===c.id;});});showMenu();})
    .catch(function(e){app.textContent='';app.appendChild(el('p','empty',e.message));});
}
function showMenu(){
  state.view='menu';back.hidden=boot.branches.length<2;sub.textContent=state.branch.name;app.textContent='';
  var search=el('input','search');search.type='search';search.placeholder='دوّر على صنف…';search.value=state.q;
  search.addEventListener('input',function(){state.q=search.value.trim();renderList();});app.appendChild(search);
  if(state.categories.length){var cats=el('div','cats');[{id:'',name:'الكل'}].concat(state.categories).forEach(function(c){var b=el('button',String(state.cat)===String(c.id)?'on':'',c.name);b.type='button';
    b.addEventListener('click',function(){state.cat=c.id;Array.prototype.forEach.call(cats.children,function(x){x.className='';});b.className='on';renderList();});cats.appendChild(b);});app.appendChild(cats);}
  var list=el('div');list.id='list';app.appendChild(list);renderList();refreshBar();
}
function renderList(){
  var list=document.getElementById('list');if(!list)return;list.textContent='';
  var shown=state.products.filter(function(p){return (state.cat===''||p.category_id===state.cat)&&(!state.q||p.name.indexOf(state.q)!==-1);});
  if(!shown.length)list.appendChild(el('p','empty','مفيش أصناف هنا.'));
  shown.forEach(function(p){
    var card=el('article','product'),pic=el('img');pic.src=img(p.image_url);pic.alt='';pic.loading='lazy';card.appendChild(pic);
    var info=el('div','info');info.appendChild(el('h3','',p.name));
    var price=el('div','price');info.appendChild(price);
    var opts=optionsOf(p),sel=null,current=opts[0].id;
    if(opts.length>1){sel=el('select');opts.forEach(function(o){var op=el('option','',o.label+' — '+money(Number(o.price)));op.value=o.id;sel.appendChild(op);});info.appendChild(sel);}
    var qty=el('div','qty');info.appendChild(qty);card.appendChild(info);
    function key(){return p.id+'|'+current;}
    function draw(){var q=(state.cart[key()]||{}).qty||0;price.textContent='';price.appendChild(document.createTextNode(money(priceOf(p,current))));price.appendChild(el('small','',' / '+unit(p)));
      qty.textContent='';
      if(!q){var add=el('button','add','+ أضف');add.type='button';add.addEventListener('click',function(){change(1);});qty.appendChild(add);return;}
      var minus=el('button','minus','−'),plus=el('button','plus','+');minus.type=plus.type='button';minus.setAttribute('aria-label','تقليل');plus.setAttribute('aria-label','زيادة');
      minus.addEventListener('click',function(){change(-1);});plus.addEventListener('click',function(){change(1);});
      qty.appendChild(minus);qty.appendChild(el('output','',num(q)+' '+unit(p)));qty.appendChild(plus);}
    function change(d){var k=key(),c=state.cart[k]||{id:p.id,opt:current,qty:0};
      // The first tap adds one whole kilo or piece; then +/- move by a quarter kilo or one piece.
      c.qty=c.qty===0&&d>0?1:Math.round((c.qty+d*step(p))*1000)/1000;
      if(c.qty>99)c.qty=99;if(c.qty<=0)delete state.cart[k];else state.cart[k]=c;draw();refreshBar();}
    if(sel)sel.addEventListener('change',function(){current=sel.value;draw();});
    draw();list.appendChild(card);
  });
}
function showCheckout(){
  state.view='checkout';back.hidden=false;sub.textContent='بيانات التوصيل';app.textContent='';refreshBar();window.scrollTo(0,0);
  var form=el('form');form.noValidate=true;
  var ul=el('ul','lines');lines().forEach(function(l){var o=optionsOf(l.p).find(function(x){return x.id===l.opt;});var li=el('li');
    li.appendChild(el('span','',l.p.name+(l.opt&&o?' ('+o.label+')':'')+' × '+num(l.qty)+' '+unit(l.p)));li.appendChild(el('b','',money(l.price*l.qty)));ul.appendChild(li);});
  form.appendChild(ul);var t=el('div','total');t.appendChild(el('span','','الإجمالي التقريبي'));t.appendChild(el('span','',money(totals().sum)));form.appendChild(t);
  form.appendChild(el('p','hint','من فرع '+state.branch.name+' · مصاريف التوصيل بتتحدد بعد مراجعة العنوان.'));
  [['customer_name','الاسم','text','name'],['customer_phone','رقم الموبايل','tel','tel'],['area','المنطقة','text',''],['address','العنوان بالتفصيل (الشارع، رقم العمارة، الدور)','text','street-address'],['notes','ملاحظات (اختياري)','textarea','']].forEach(function(f){
    var lab=el('label','',f[1]);lab.htmlFor='f-'+f[0];var inp=el(f[2]==='textarea'?'textarea':'input');inp.id='f-'+f[0];inp.name=f[0];if(f[2]!=='textarea')inp.type=f[2];if(f[3])inp.autocomplete=f[3];
    if(f[0]==='customer_phone'){inp.inputMode='tel';inp.dir='ltr';inp.placeholder='01xxxxxxxxx';}
    try{var saved=localStorage.getItem('chatmenu.'+f[0]);if(saved&&f[0]!=='notes')inp.value=saved;}catch(e){}
    form.appendChild(lab);form.appendChild(inp);});
  var err=el('p','err');form.appendChild(err);var send=el('button','cta','تأكيد وإرسال الطلب');send.type='submit';send.style.width='100%';send.style.marginTop='6px';form.appendChild(send);
  form.addEventListener('submit',function(ev){ev.preventDefault();err.textContent='';
    var v={};['customer_name','customer_phone','area','address','notes'].forEach(function(n){v[n]=form.elements[n].value.trim();});
    if(v.customer_name.length<2)return err.textContent='اكتب الاسم.';if(!/^[0-9٠-٩\s-]{11,15}$/.test(v.customer_phone))return err.textContent='اكتب رقم موبايل صحيح من ١١ رقم.';
    if(v.area.length<2)return err.textContent='اكتب المنطقة.';if(v.address.length<8)return err.textContent='اكتب العنوان بالتفصيل.';
    try{['customer_name','customer_phone','area','address'].forEach(function(n){localStorage.setItem('chatmenu.'+n,v[n]);});}catch(e){}
    send.disabled=true;send.textContent='جاري الإرسال…';
    v.branch=state.branch.id;v.items=lines().map(function(l){return {product_id:l.p.id,option_id:l.opt,quantity:String(l.qty)};});
    request(boot.submit,{method:'POST',body:JSON.stringify(v)}).then(function(){done();}).catch(function(e){err.textContent=e.message;send.disabled=false;send.textContent='تأكيد وإرسال الطلب';});});
  app.appendChild(form);
}
function done(){state.view='done';back.hidden=true;refreshBar();sub.textContent='تم';app.textContent='';
  var n=el('div','notice');n.appendChild(el('div','big','✅'));n.appendChild(el('strong','','طلبك اتبعت للشات'));n.appendChild(el('p','','ارجع للمحادثة، هتلاقي ملخص طلبك. لو كله تمام ابعت «موافق على الطلب» وهنأكدلك ونجهزه.'));app.appendChild(n);}
document.getElementById('next').addEventListener('click',showCheckout);
back.addEventListener('click',function(){if(state.view==='checkout')showMenu();else if(state.view==='menu')showBranches();});
showBranches();
}());
</script>
@endif
</body>
</html>
