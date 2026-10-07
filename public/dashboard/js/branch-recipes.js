(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root=document.getElementById('branch-stock');if(!root)return;
    var boot=JSON.parse(document.getElementById('branch-stock-bootstrap').textContent),panel=root.querySelector('[data-recipe-panel]'),form=panel.querySelector('form'),branch=root.querySelector('[data-stock-branch]'),search=panel.querySelector('[data-recipe-search]'),filter=panel.querySelector('[data-recipe-filter]');
    var ingredients=new Map((boot.initial.ingredients||[]).map(function(i){return [String(i.id),i];})),products=new Map(),chosen=null,feature='0',dirty=false,busy=false,loading=false,frozen=null,disposed=false,loadedBranch='',generation=0,controller,listeners=[];
    var canManage=!!boot.initial.can_manage_recipes,pendingKey='fasakhansta:recipe-pending:'+boot.actor_id;
    function el(tag,text,css){var n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);if(css)n.className=css;return n;}
    function on(target,event,fn){target.addEventListener(event,fn);listeners.push(function(){target.removeEventListener(event,fn);});}
    function notice(message,retry){var box=panel.querySelector('[data-recipe-message]');box.hidden=!message;box.textContent=message||'';panel.querySelector('[data-recipe-retry]').hidden=!retry;}
    function url(value,params){var u=new URL(value,location.href);if(u.origin!==location.origin)throw new Error('رابط العملية غير صالح.');Object.keys(params||{}).forEach(function(k){u.searchParams.set(k,params[k]);});return u.href;}
    function locked(){return busy||loading||!!frozen;}
    function lock(){
        panel.querySelectorAll('button,input,select').forEach(function(c){c.disabled=locked();});search.disabled=busy||!!frozen;
        panel.querySelector('[data-recipe-retry]').disabled=busy||loading;
        if(!locked()){
            form.querySelectorAll('button,input,select').forEach(function(c){c.disabled=!canManage;});
            form.elements.feature_id.disabled=false;if(chosen&&chosen.recipe&&chosen.recipe.id)form.elements.unit.disabled=true;
        }
        panel.querySelector('[data-recipe-save]').hidden=!canManage;panel.querySelector('[data-recipe-add]').hidden=!canManage;
        panel.querySelector('[data-recipe-save]').textContent=busy?'جارٍ حفظ الوصفة…':'حفظ وصفة الصنف';
    }
    async function request(address,options){
        var aborter=new AbortController(),timeout=setTimeout(function(){aborter.abort();},20000);
        var upstream=options&&options.signal,cancel=function(){aborter.abort();};if(upstream){if(upstream.aborted)cancel();else upstream.addEventListener('abort',cancel,{once:true});}
        try{var response=await fetch(address,Object.assign({credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}},options||{},{signal:aborter.signal}));var data;try{data=await response.json();}catch(_){data=null;}
            if(!response.ok||!data||data.success===false){var error=new Error(response.status===401||response.status===419?'انتهت الجلسة؛ سجل الدخول ثم تحقق من نتيجة الحفظ.':data&&data.message||'تعذر إتمام الطلب.');error.status=response.status;throw error;}return data;
        }finally{clearTimeout(timeout);if(upstream)upstream.removeEventListener('abort',cancel);}
    }
    function ingredientRow(value){
        value=value||{};var row=el('div',undefined,'bs-component'),select=el('select'),quantity=el('input'),measure=el('select'),remove=el('button','×');
        select.dataset.recipeIngredient='';select.setAttribute('aria-label','المكوّن');select.required=true;var empty=el('option','اختر المكوّن');empty.value='';select.appendChild(empty);
        ingredients.forEach(function(i){var option=el('option',i.name);option.value=i.id;select.appendChild(option);});select.value=value.ingredient_id?String(value.ingredient_id):'';
        quantity.dataset.recipeQuantity='';quantity.type='text';quantity.inputMode='decimal';quantity.maxLength=16;quantity.placeholder='0';quantity.required=true;quantity.setAttribute('aria-label','مقدار المكوّن');quantity.value=value.quantity||'';
        measure.dataset.recipeMeasure='';measure.setAttribute('aria-label','وحدة مقدار المكوّن');
        function measures(selected){var i=ingredients.get(select.value);measure.replaceChildren();(i&&i.unit==='piece'?[['piece','قطعة']]:[['g','جرام'],['kg','كيلو']]).forEach(function(pair){var option=el('option',pair[1]);option.value=pair[0];measure.appendChild(option);});if(selected)measure.value=selected;}
        measures(value.measure);select.addEventListener('change',function(){measures();quantity.value='';dirty=true;});measure.addEventListener('change',function(){quantity.value='';dirty=true;});
        remove.type='button';remove.setAttribute('aria-label','حذف المكوّن');remove.addEventListener('click',function(){if(locked()||!canManage)return;row.remove();dirty=true;});row.append(select,quantity,measure,remove);return row;
    }
    function renderVariant(){
        var body=panel.querySelector('[data-recipe-components]');body.replaceChildren();var values=chosen&&chosen.recipe&&chosen.recipe.variants[feature]||chosen&&chosen.recipe_hint||[];
        (values.length?values:[{}]).forEach(function(c){body.appendChild(ingredientRow(c));});dirty=false;lock();
    }
    function selectProduct(item,force){
        if(!force&&(locked()||dirty&&!confirm('ترك تعديلات الوصفة غير المحفوظة؟')))return;
        chosen=item;feature='0';form.hidden=!item;panel.querySelector('[data-recipe-empty]').hidden=!!item;
        panel.querySelector('[data-recipe-title]').textContent=item?item.name:'اختر صنفًا لتسجيل وصفته';
        panel.querySelector('[data-recipe-revision]').textContent=item&&item.recipe?(item.recipe.raw_stock?'مرتبط مباشرة برصيد البضاعة':'نسخة الوصفة '+item.recipe.revision):item&&item.stock_source==='ingredient_preview'?'سجّل وزن الخضار بالجرام لكل طبق لتفعيل الخصم من البضاعة':!canManage?'عرض الوصفات — التعديل من حساب الإدارة أو المالك':'';
        if(!item){dirty=false;return;}
        form.elements.feature_id.replaceChildren();item.features.forEach(function(f){var option=el('option',f.label+(item.recipe&&item.recipe.variants[String(f.id)]?' · مسجلة':' · غير مسجلة'));option.value=f.id;form.elements.feature_id.appendChild(option);});
        form.elements.unit.value=item.recipe?item.recipe.unit:'piece';renderVariant();renderProducts();
    }
    function needsRecipe(item){return !['ingredient','direct'].includes(item.stock_source)&&item.features.some(function(f){var components=item.recipe&&item.recipe.variants[String(f.id)];return !components||!components.length;});}
    function renderProducts(){
        var term=search.value.trim().toLocaleLowerCase(),missing=0,items=[];products.forEach(function(item){var needs=needsRecipe(item);if(needs)missing++;if(term&&!item.name.toLocaleLowerCase().includes(term)||filter.value==='missing'&&!needs||filter.value==='ready'&&needs)return;items.push(item);});
        items.sort(function(a,b){return Number(needsRecipe(b))-Number(needsRecipe(a))||a.name.localeCompare(b.name,'ar')||a.id-b.id;});
        var list=panel.querySelector('[data-recipe-products]');list.replaceChildren();items.forEach(function(item){var button=el('button'),name=el('span',item.name),count=item.features.filter(function(f){return item.recipe&&item.recipe.variants[String(f.id)];}).length,badge=el('small',item.stock_source==='direct'?'رصيد وحدة مستقل':item.stock_source==='ingredient_preview'?'يحتاج وزن الطبق':item.stock_source==='unconfigured'?'يحتاج وصفة':item.stock_source==='ingredient'?'مرتبط بالبضاعة':count+'/'+item.features.length+' أحجام',!needsRecipe(item)?'is-ready':'');button.type='button';button.dataset.recipeProduct=item.id;button.setAttribute('aria-pressed',String(!!chosen&&chosen.id===item.id));button.append(name,badge);button.addEventListener('click',function(){selectProduct(item,false);});list.appendChild(button);});if(!items.length)list.appendChild(el('p','لا توجد أصناف مطابقة.','bs-note'));
        panel.querySelector('[data-recipe-page]').textContent='عرض '+items.length+' من '+products.size+' صنف · '+missing+' يحتاج وصفة';
    }
    async function load(selectId,selectFeature){
        if(busy||frozen||disposed)return;if(controller)controller.abort();controller=new AbortController();var token=++generation,atBranch=branch.value;loading=true;lock();
        var signal=controller.signal;panel.querySelector('[data-recipe-page]').textContent='جارٍ تحميل كل أصناف البيع…';
        try{var data=null,all=new Map(),total,last;
            // Keep the API paginated, but expose one complete branch menu in the recipe editor.
            for(var page=1;page<=(last||1);page++){
                var result=await request(url(boot.urls.recipes,{branch:atBranch,page:page}),{signal:signal});if(disposed||token!==generation||branch.value!==atBranch)return;
                if(page===1){data=result;total=result.pagination.total;last=result.pagination.last_page;}
                if(!Number.isInteger(total)||total<0||!Number.isInteger(last)||last<1||result.pagination.page!==page||result.pagination.total!==total||result.pagination.last_page!==last)throw new Error('قائمة الأصناف تغيرت أثناء التحميل؛ اضغط تحديث.');
                result.items.forEach(function(item){var id=String(item.id);if(all.has(id))throw new Error('قائمة الأصناف تغيرت أثناء التحميل؛ اضغط تحديث.');all.set(id,item);});
            }
            if(all.size!==total)throw new Error('لم تكتمل قائمة أصناف البيع؛ اضغط تحديث لإعادة تحميلها.');
            loadedBranch=atBranch;canManage=!!data.can_manage;products=all;ingredients=new Map(data.ingredients.map(function(i){return [String(i.id),i];}));renderProducts();notice('');
            if(selectId&&products.has(String(selectId))){selectProduct(products.get(String(selectId)),true);if(selectFeature!==undefined){feature=String(selectFeature);form.elements.feature_id.value=feature;renderVariant();}}
            else if(selectId)selectProduct(null,true);
        }catch(error){if(!disposed&&token===generation&&!signal.aborted){renderProducts();notice(error.name==='AbortError'?'انتهت مهلة تحميل الأصناف؛ اضغط تحديث.':error.message);}}
        finally{if(!disposed&&token===generation){loading=false;lock();}}
    }
    function matches(data,op){var r=data&&data.operation;return data.recipe&&r&&r.branch===op.branch&&r.product_id===op.product_id&&r.feature_id===op.feature_id&&r.idempotency_key===op.idempotency_key;}
    function setBusy(value){busy=value;root.dispatchEvent(new CustomEvent('inventory:busy',{detail:value||!!frozen}));lock();}
    function clearPending(){localStorage.removeItem(pendingKey);frozen=null;}
    async function save(op,retry,readOnly){
        if(busy||loading)return;
        try{localStorage.setItem(pendingKey,JSON.stringify(op));if(localStorage.getItem(pendingKey)!==JSON.stringify(op))throw new Error();}catch(_){notice('فعّل تخزين المتصفح لحماية الوصفة من تكرار الحفظ.');return;}
        frozen=op;setBusy(true);var done=false;
        try{
            var data;if(retry||readOnly){var found=await request(url(boot.urls.recover,{branch:op.branch,idempotency_key:op.idempotency_key}));if(found.found)data=found;}
            if(!data&&readOnly){notice('يوجد حفظ سابق غير مؤكد. اضغط التحقق لإكماله بنفس رقم العملية.',true);return;}
            if(!data)data=await request(url(boot.urls.recipe_save),{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(op)});
            if(!matches(data,op))throw new Error('تعذر التأكد من نتيجة حفظ الوصفة.');
            clearPending();dirty=false;done=true;notice('تم حفظ وصفة الصنف. الخصم التالي يستخدم المقادير المسجلة.');
        }catch(error){if([403,404,422].includes(error.status)){clearPending();notice(error.message);}else if(error.status===409){
                // A confirmed conflict cannot have written this operation; resolve its ledger before releasing the draft.
                try{var check=await request(url(boot.urls.recover,{branch:op.branch,idempotency_key:op.idempotency_key}));if(!check.found){clearPending();notice(error.message+' حدّث الوصفة وأعد مراجعة المقادير.');}else notice(error.message,true);}catch(_){notice(error.message,true);}
            }else notice('لم نتأكد من الحفظ. '+(error.name==='AbortError'?'انتهت مهلة الاتصال.':error.message),true);
        }finally{setBusy(false);}
        if(done){root.dispatchEvent(new Event('inventory:recipe-saved'));load(op.product_id,op.feature_id);}
    }
    on(form,'submit',function(event){event.preventDefault();if(locked()||!canManage||!chosen)return;
        var components=Array.from(panel.querySelectorAll('.bs-component')).map(function(row){return {ingredient_id:Number(row.querySelector('[data-recipe-ingredient]').value),quantity:row.querySelector('[data-recipe-quantity]').value.trim().replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}).replace(/٫/g,'.'),measure:row.querySelector('[data-recipe-measure]').value};});
        if(!components.length||components.some(function(c){return !c.ingredient_id||!/^\d{1,7}(?:\.\d{1,3})?$/.test(c.quantity)||Number(c.quantity)<=0;})||new Set(components.map(function(c){return c.ingredient_id;})).size!==components.length){notice('أدخل مكوّنات مختلفة ومقدارًا موجبًا لكل مكوّن.');return;}
        var op={branch:branch.value,product_id:chosen.id,feature_id:Number(feature),unit:form.elements.unit.value,components:components,idempotency_key:crypto.randomUUID()};if(chosen.recipe&&chosen.recipe.id)op.expected_revision=chosen.recipe.revision;save(op,false,false);
    });
    on(form,'input',function(event){if(event.target!==form.elements.feature_id)dirty=true;});on(form.elements.feature_id,'change',function(){if(dirty&&!confirm('ترك مقادير الحجم غير المحفوظة؟')){form.elements.feature_id.value=feature;return;}feature=form.elements.feature_id.value;renderVariant();});
    on(panel.querySelector('[data-recipe-add]'),'click',function(){if(locked()||!canManage)return;if(panel.querySelectorAll('.bs-component').length>=ingredients.size){notice('تم بلوغ عدد أصناف البضاعة.');return;}panel.querySelector('[data-recipe-components]').appendChild(ingredientRow());dirty=true;});
    on(search,'input',renderProducts);on(filter,'change',renderProducts);
    on(panel.querySelector('[data-recipe-refresh]'),'click',function(){if(dirty&&!confirm('إعادة تحميل الوصفة وترك التعديلات غير المحفوظة؟'))return;var id=chosen&&chosen.id;dirty=false;load(id,feature);});
    on(panel.querySelector('[data-recipe-retry]'),'click',function(){if(frozen)save(frozen,true,false);});
    root.querySelectorAll('[data-inventory-tab]').forEach(function(button){on(button,'click',function(){if(button.disabled||busy||frozen)return;var target=button.dataset.inventoryTab;root.dataset.inventoryTab=target;root.querySelectorAll('[data-inventory-panel]').forEach(function(p){p.hidden=p.dataset.inventoryPanel!==target;});root.querySelectorAll('[data-inventory-tab]').forEach(function(b){b.setAttribute('aria-pressed',String(b===button));});if(target==='recipes'&&loadedBranch!==branch.value)load();});});
    on(root,'inventory:before-branch',function(event){if(busy||frozen||dirty&&!confirm('ترك تعديلات الوصفة وتغيير الفرع؟'))event.preventDefault();});
    on(root,'inventory:branch',function(){generation++;if(controller)controller.abort();loading=false;loadedBranch='';products.clear();chosen=null;dirty=false;search.value='';filter.value='all';selectProduct(null,true);renderProducts();if(!panel.hidden)load();});
    on(window,'beforeunload',function(event){if(dirty||frozen||busy){event.preventDefault();event.returnValue='';}});
    if(window.DashboardSPA){window.DashboardSPA.onBeforeLeave(function(){if(frozen||busy){notice('تحقق من نتيجة حفظ الوصفة قبل المغادرة.',true);return false;}return !dirty||confirm('مغادرة الصفحة دون حفظ الوصفة؟');});window.DashboardSPA.onCleanup(function(){disposed=true;generation++;if(controller)controller.abort();listeners.forEach(function(off){off();});});}
    lock();try{var stored=JSON.parse(localStorage.getItem(pendingKey)||'null');if(stored){branch.value=stored.branch;root.querySelector('[data-inventory-tab="recipes"]').click();frozen=stored;loading=false;generation++;if(controller)controller.abort();save(stored,true,true);}}catch(_){notice('تعذر قراءة عملية حفظ الوصفة السابقة.');}
}());
