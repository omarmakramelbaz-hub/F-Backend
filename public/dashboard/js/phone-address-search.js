(function(){
'use strict';
if(window.PhoneAddressSearch)return;
window.PhoneAddressSearch={create:function(options){
 var input=options.input,box=options.box,status=options.status,items=[],active=-1,generation=0,timer,flight=null,queued=false,disposed=false,cache=new Map(),retries=0;
 input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-expanded','false');input.setAttribute('aria-controls',box.id);input.setAttribute('autocomplete','off');
 function hide(){items=[];active=-1;box.replaceChildren();box.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');}
 function invalidate(){generation++;clearTimeout(timer);queued=false;hide();status.textContent='';}
 function reset(){invalidate();cache.clear();if(flight)flight.controller.abort();}
 function current(g){return !disposed&&g===generation&&!options.locked();}
 function display(result){hide();items=result.slice(0,6);status.textContent=items.length?'اختر العنوان الأقرب ثم راجع الدبوس.':'لا توجد نتائج مطابقة. جرّب اسم الشارع أو المعلم والمنطقة، أو حدد الدبوس يدويًا.';
  items.forEach(function(item,i){var button=document.createElement('button');button.type='button';button.id=box.id+'-'+i;button.setAttribute('role','option');button.setAttribute('aria-selected','false');button.textContent=item.label;button.addEventListener('click',function(){select(i);});box.appendChild(button);});box.hidden=!items.length;input.setAttribute('aria-expanded',String(!!items.length));
 }
 async function search(){clearTimeout(timer);var query=options.query().trim().replace(/\s+/g,' ').slice(0,240);if(query.length<2||options.locked()||disposed)return;var g=generation;
  if(cache.has(query)){display(cache.get(query));return;}
  if(flight){queued=true;status.textContent='جارٍ تحديث اقتراحات العنوان…';return;}
  var task={controller:new AbortController()},timeout=setTimeout(function(){task.controller.abort();},7000);flight=task;queued=false;status.textContent='جارٍ البحث عن عناوين…';
  try{var result=await Promise.race([options.search(query,task.controller.signal),new Promise(function(_,reject){task.controller.signal.addEventListener('abort',function(){var e=Error('Search aborted');e.name='AbortError';reject(e);},{once:true});})]);if(!current(g))return;cache.set(query,result);if(cache.size>30)cache.delete(cache.keys().next().value);retries=0;display(result);
  }catch(e){if(current(g)){if(e.status===429&&retries<3){retries++;status.textContent='جارٍ تحديث اقتراحات العنوان…';timer=setTimeout(search,1100);}else status.textContent=e.name==='AbortError'?'تأخر البحث. جرّب كتابة اسم المنطقة أو اضغط بحث العنوان.':'تعذر عرض العناوين. '+(e.message||'حاول مرة أخرى.');}}
  finally{clearTimeout(timeout);if(flight===task)flight=null;if(queued&&!disposed){queued=false;timer=setTimeout(search,0);}}
 }
 async function select(i){var item=items[i];if(!item||options.locked())return;invalidate();var g=generation;if(flight)flight.controller.abort();status.textContent='جارٍ تحديد موقع العنوان…';try{var point=await options.resolve(item);if(!current(g))return;options.select(point);status.textContent='العنوان المختار: '+point.label+' — راجع موقع مدخل العميل ثم أكد الدبوس.';}catch(e){if(current(g))status.textContent=e.message||'تعذر تحديد العنوان.';}}
 function key(e){if(e.key==='Escape'){invalidate();e.stopPropagation();return;}if(box.hidden||!items.length)return;if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();e.stopPropagation();active=(active+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;Array.from(box.children).forEach(function(el,i){el.setAttribute('aria-selected',String(i===active));});input.setAttribute('aria-activedescendant',box.children[active].id);box.children[active].scrollIntoView({block:'nearest'});}else if(e.key==='Enter'){e.preventDefault();e.stopPropagation();if(active>=0)select(active);else status.textContent='اختر عنوانًا من القائمة بالسهم لأسفل ثم Enter، أو بالماوس.';}}
 input.addEventListener('keydown',key);
 return{search:function(){invalidate();retries=0;search();},schedule:function(){invalidate();retries=0;timer=setTimeout(search,250);},reset:reset,destroy:function(){reset();disposed=true;input.removeEventListener('keydown',key);}};
}};
}());
