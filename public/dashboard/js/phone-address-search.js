(function(){
'use strict';
if(window.PhoneAddressSearch)return;
window.PhoneAddressSearch={create:function(options){
 var input=options.input,box=options.box,status=options.status,items=[],active=-1,generation=0,timer,aborter,disposed=false;
 input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');input.setAttribute('aria-controls',box.id);input.setAttribute('autocomplete','off');
 function hide(){items=[];active=-1;box.replaceChildren();box.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');}
 function reset(){generation++;clearTimeout(timer);if(aborter)aborter.abort();hide();status.textContent='';}
 function current(g){return !disposed&&g===generation&&!options.locked();}
 async function search(){reset();var query=options.query().trim();if(query.length<3||options.locked())return;var g=generation;aborter=new AbortController();var requestController=aborter,timeout=setTimeout(function(){requestController.abort();},14000);status.textContent='جارٍ البحث عن عناوين قريبة…';
  try{var result=await Promise.race([options.search(query.slice(0,240),requestController.signal),new Promise(function(_,reject){requestController.signal.addEventListener('abort',function(){var e=Error('Search aborted');e.name='AbortError';reject(e);},{once:true});})]);if(!current(g))return;items=result.slice(0,6);status.textContent=items.length?'اختر العنوان الأقرب ثم راجع الدبوس.':'لا توجد نتائج مطابقة. اكتب اسم الشارع والمنطقة والمدينة، أو حدد الدبوس يدويًا.';
   items.forEach(function(item,i){var button=document.createElement('button');button.type='button';button.id=box.id+'-'+i;button.setAttribute('role','option');button.setAttribute('aria-selected','false');button.textContent=item.label;button.addEventListener('click',function(){select(i);});box.appendChild(button);});box.hidden=!items.length;input.setAttribute('aria-expanded',String(!!items.length));
  }catch(e){if(current(g))status.textContent=e.name==='AbortError'?'تأخر البحث. اضغط بحث العنوان لإعادة المحاولة.':'تعذر عرض العناوين. '+(e.message||'حاول مرة أخرى.');}finally{clearTimeout(timeout);}
 }
 async function select(i){var item=items[i];if(!item||options.locked())return;generation++;var g=generation;clearTimeout(timer);if(aborter)aborter.abort();hide();status.textContent='جارٍ تحديد موقع العنوان…';try{var point=await options.resolve(item);if(!current(g))return;options.select(point);status.textContent='العنوان المختار: '+point.label+' — راجع موقع مدخل العميل ثم أكد الدبوس.';}catch(e){if(current(g))status.textContent=e.message||'تعذر تحديد العنوان.';}}
 function key(e){if(e.key==='Escape'){reset();e.stopPropagation();return;}if(box.hidden||!items.length)return;if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();e.stopPropagation();active=(active+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;Array.from(box.children).forEach(function(el,i){el.setAttribute('aria-selected',String(i===active));});input.setAttribute('aria-activedescendant',box.children[active].id);box.children[active].scrollIntoView({block:'nearest'});}else if(e.key==='Enter'){e.preventDefault();e.stopPropagation();if(active>=0)select(active);else status.textContent='اختر عنوانًا من القائمة بالسهم لأسفل ثم Enter، أو بالماوس.';}}
 input.addEventListener('keydown',key);
 return{search:search,schedule:function(){reset();timer=setTimeout(search,700);},reset:reset,destroy:function(){reset();disposed=true;input.removeEventListener('keydown',key);}};
}};
}());
