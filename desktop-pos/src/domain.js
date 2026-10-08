(function(root, factory) { const api=factory(); if(typeof module==='object') module.exports=api; else root.POSDomain=api; })(typeof window==='object'?window:globalThis, function() {
  const MAX=100000000;
  function decimal(value,places=2) {
    const text=String(value??'0').replace(/[٠-٩]/g,c=>'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/[٫]/g,'.');
    if(!new RegExp('^[0-9]{1,7}(?:\\.[0-9]{1,'+places+'})?$').test(text)) throw Error('أدخل قيمة رقمية صحيحة.');
    const [whole,part='']=text.split('.'); const n=Number(whole)*10**places+Number(part.padEnd(places,'0'));
    if(!Number.isSafeInteger(n)) throw Error('القيمة أكبر من الحد المسموح.'); return n;
  }
  function money(n) { return (n/100).toFixed(2); }
  function round(numerator,denominator) { return Number((BigInt(numerator)+BigInt(denominator/2))/BigInt(denominator)); }
  function bounded(n,max=MAX) { if(!Number.isSafeInteger(n)||n<0||n>max) throw Error('قيمة غير صحيحة.'); return n; }
  function clean(data) {
    if(!['takeaway','dine','phone'].includes(data.channel)||!Array.isArray(data.items)||data.items.length>100) throw Error('بيانات الطلب غير صحيحة.');
    const out={channel:data.channel,items:data.items.map(i=>({product_id:bounded(Number(i.product_id),Number.MAX_SAFE_INTEGER),option_id:String(i.option_id||''),quantity_mode:i.quantity_mode,quantity:String(i.quantity)})),delivery_cents:bounded(Number(data.delivery_cents||0)),discount:money(decimal(data.discount)),discount_reason:String(data.discount_reason||''),cash_received:money(decimal(data.cash_received))};
    for(const [key,max] of Object.entries({customer_name:100,customer_phone:30,address:500,table_name:100,notes:500})) {out[key]=String(data[key]||'').trim(); if(out[key].length>max) throw Error('البيانات أطول من الحد المسموح.');}
    if(out.discount_reason.length>500) throw Error('سبب الخصم طويل.'); return out;
  }
  function quote(snapshot,data) {
    const d=clean(data); const lines=[]; let subtotal=0;
    const merged=new Map();
    for(const item of d.items) {
      const p=snapshot.products.find(p=>p.id===item.product_id), v=p?.variants.find(v=>v.option_id===item.option_id);
      if(!p||!v||!['piece','weight'].includes(item.quantity_mode)||(v.quantity_mode!=='select'&&v.quantity_mode!==item.quantity_mode)) throw Error('الصنف أو الوحدة غير متاح في المينيو المحفوظ.');
      if(!Number.isSafeInteger(v.unit_price_cents)||v.unit_price_cents<=0)throw Error('سعر الصنف غير محدد في المينيو. حدّث الأسعار من الداشبورد قبل استخدامه.');
      const qty=decimal(item.quantity,3);
      if(qty<1||qty>1000000||(item.quantity_mode==='piece'&&qty%1000!==0)) throw Error('العدد صحيح أو الوزن حتى ٣ منازل عشرية، وبحد أقصى ١٠٠٠.');
      const key=item.product_id+'|'+item.option_id+'|'+item.quantity_mode;
      const old=merged.get(key); if(old) old.qty+=qty; else merged.set(key,{p,v,qty,mode:item.quantity_mode});
    }
    for(const {p,v,qty,mode} of merged.values()) {
      bounded(qty,1000000); const total=round(BigInt(v.unit_price_cents)*BigInt(qty),1000); subtotal+=total;
      lines.push({product_id:p.id,name:p.name,option_id:v.option_id,label:v.label,mode,quantity:(qty/1000).toFixed(3),unit_price_cents:v.unit_price_cents,total_cents:total});
    }
    bounded(subtotal); const discount=decimal(d.discount); if(discount>subtotal||(discount>0&&(!snapshot.can_discount||!d.discount_reason))) throw Error('الخصم غير مسموح أو يحتاج سببًا.');
    if(d.channel!=='phone'&&d.delivery_cents!==0) throw Error('خدمة التوصيل لطلبات الهاتف فقط.');
    const service=d.channel==='dine'?round(BigInt(subtotal-discount)*BigInt(snapshot.service_bps),10000):0;
    const tax=round(BigInt(subtotal-discount+service+d.delivery_cents)*BigInt(snapshot.tax_bps),10000);
    const total=bounded(subtotal-discount+service+d.delivery_cents+tax);
    return {items:lines,subtotal_cents:subtotal,discount_cents:discount,service_cents:service,tax_cents:tax,delivery_cents:d.delivery_cents,total_cents:total,change_cents:Math.max(0,decimal(d.cash_received)-total)};
  }
  function validateAction(status,kind,data,snapshot) {
    const d=clean(data), q=quote(snapshot,d);
    if(!q.items.length) throw Error('أضف أصنافًا للفاتورة.');
    if(['paid','cancelled'].includes(status)) throw Error('الفاتورة منتهية.');
    if(d.channel==='phone'&&(!d.customer_name||!(/^[+0-9 ()-]{6,30}$/).test(d.customer_phone)||!d.address)) throw Error('أكمل اسم العميل ورقمه وعنوانه.');
    if(d.channel==='dine'&&(!d.table_name||!d.customer_name)) throw Error('أدخل الطاولة واسم العميل.');
    if(kind==='sale') {if(decimal(d.cash_received)<q.total_cents) throw Error('المبلغ المستلم أقل من إجمالي الفاتورة.');return 'paid';}
    if(kind==='cancel') {if(status!=='draft'||!String(data.cancel_reason||'').trim()) throw Error('لا يمكن إلغاء طلب أرسل للمطبخ أو صدر حسابه.');return 'cancelled';}
    if(kind==='kitchen'||kind==='bill') {if(!['draft','kitchen'].includes(status)) throw Error('صدرت فاتورة الحساب؛ أكمل التحصيل.');return kind;}
    if(kind==='save'&&status!=='bill') return status;
    throw Error('العملية غير مسموحة.');
  }
  return {decimal,money,quote,clean,validateAction};
});
