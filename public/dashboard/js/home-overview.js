(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    var root=document.getElementById('home-overview');if(!root)return;
    var boot=JSON.parse(document.getElementById('home-overview-bootstrap').textContent),text=boot.labels,form=root.querySelector('[data-ho-filters]');
    var data=boot.initial,period=data.filters.period,disposed=false,controller,sequence=0,timer,listeners=[],editing=false,lastLoaded=Date.now(),ownerObserver,ownerFrame;
    var colors={app:'#508ef2',takeaway:'#f8b758',phone:'#67c4a1',dine:'#ad8ee6'},icons={app:'mobile-alt',takeaway:'shopping-bag',phone:'motorcycle',dine:'utensils'};
    var numbers=new Intl.NumberFormat(boot.locale==='ar'?'ar-EG-u-nu-latn':'en-GB',{maximumFractionDigits:2});
    function $(name){return root.querySelector('[data-ho-'+name+']');}
    function el(tag,value,css){var n=document.createElement(tag);if(value!==undefined)n.textContent=String(value);if(css)n.className=css;return n;}
    function icon(name,css){var n=el('i',undefined,'fas fa-'+name+(css?' '+css:''));n.setAttribute('aria-hidden','true');return n;}
    function on(target,name,fn){if(!target)return;target.addEventListener(name,fn);listeners.push(function(){target.removeEventListener(name,fn);});}
    function fmt(value){return value===null||value===undefined?'—':numbers.format(value);}
    function decimal(value){return new Intl.NumberFormat(boot.locale==='ar'?'ar-EG-u-nu-latn':'en-GB',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100);}
    function money(value){return decimal(value)+' '+text.currency;}
    function safeLink(address){var u=new URL(address,location.href);return u.origin===location.origin?u.href:'#';}
    function routeLink(anchor,branch){var u=new URL(anchor.href,location.href);if(branch)u.searchParams.set('branch',branch);else u.searchParams.delete('branch');anchor.href=u.href;}
    function badge(value,tone){return el('span',value,'ho-badge ho-'+tone);}
    function note(value){var n=$('notice');n.hidden=!value;n.textContent=value||'';}
    function scopeLabel(){return data.filters.branch?(data.branches.find(function(b){return b.value===data.filters.branch;})||{}).name:text.all_branches;}
    function card(label,value,unit,noteValue,iconName,tone){var n=el('article',undefined,'ho-kpi'),copy=el('div'),v=el('div',value,'ho-value');if(unit)v.appendChild(el('small',unit));copy.append(el('span',label,'ho-kpi-label'),v,el('small',noteValue,'ho-kpi-note'));n.append(copy,icon(iconName,'ho-icon ho-'+tone));return n;}
    function renderKpis(){
        var n=$('kpis');n.replaceChildren();var financial=data.can_view_financials,s=data.sales,previous=data.previous;
        var change=financial&&previous.gross_cents>0?((s.gross_cents-previous.gross_cents)*100/previous.gross_cents):null;
        var salesReady=data.modules.pos&&data.modules.app;
        if(financial)n.appendChild(card(text.gross,salesReady?decimal(s.gross_cents):'—',text.currency,change===null?text.no_comparison:(change>0?'+':'')+fmt(change)+'% '+text.comparison,'coins','purple'));
        else n.appendChild(card(text.new,fmt(data.active.new),'',text.now,'bell','purple'));
        n.appendChild(card(text.completed,salesReady?fmt(data.completed):'—','',text.completed_note,'clipboard-check','blue'));
        n.appendChild(card(text.courier,fmt(data.active.courier),'',text.now,'motorcycle','orange'));
        n.appendChild(card(text.open_branches,fmt(data.open_branches)+' / '+fmt(data.selected_branches),'',text.branch_state,'store','green'));
        n.appendChild(card(data.customers.global?text.customers:text.branch_customers,fmt(data.customers.total),'',data.customers.global?text.registered:text.within_branches,'user-friends','blue'));
        if(data.owner_drawer)n.appendChild(card(text.drawer,data.owner_drawer.ready?decimal(data.owner_drawer.total_cents):'—',text.currency,text.owner_only,'cash-register','green'));
        else if(financial)n.appendChild(card(text.net,salesReady&&data.modules.expenses?decimal(s.net_cents):'—',text.currency,text.period,'chart-line','green'));
        else n.appendChild(card(text.tracked,fmt(data.inventory.tracked),'',text.now,'boxes','teal'));
    }
    function svg(tag,attrs){var n=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.keys(attrs||{}).forEach(function(k){n.setAttribute(k,attrs[k]);});return n;}
    function chart(){
        var financial=data.can_view_financials,points=data.trend,values=points.map(function(p){return financial?p.amount_cents/100:p.count;}),max=Math.max(1,...values),w=data.owner_platform?Math.max(360,$('chart').clientWidth-24):660,h=data.owner_platform?Math.max(110,$('chart').clientHeight):190,left=48,right=18,top=15,bottom=28;
        $('trend-title').textContent=financial?text.sales_trend:text.orders_trend;$('trend-caption').textContent=data.filters.from+' — '+data.filters.to;
        $('trend-total').textContent=financial?money(data.sales.gross_cents):fmt(data.completed)+' '+text.order;
        var drawing=svg('svg',{viewBox:'0 0 '+w+' '+h,role:'img','aria-label':(financial?text.sales_trend:text.orders_trend)+' · '+data.filters.from+' — '+data.filters.to});
        drawing.style.direction='ltr';
        var title=svg('title');title.textContent=financial?text.sales_trend:text.orders_trend;drawing.appendChild(title);
        for(var i=0;i<4;i++){var y=top+(h-top-bottom)*i/3;drawing.appendChild(svg('line',{x1:left,y1:y,x2:w-right,y2:y,stroke:'#edf1f6','stroke-width':1}));var label=svg('text',{x:left-8,y:y+4,'text-anchor':'end',fill:'#8b9ab0','font-size':10});label.textContent=new Intl.NumberFormat('en',{notation:'compact',maximumFractionDigits:1}).format(max*(3-i)/3);drawing.appendChild(label);}
        var coords=values.map(function(v,i){return {x:left+(w-left-right)*(values.length===1?.5:i/(values.length-1)),y:h-bottom-(h-top-bottom)*v/max};});
        if(coords.length){var line=coords.map(function(c,i){return (i?'L':'M')+c.x+' '+c.y;}).join(' ');drawing.appendChild(svg('path',{d:line+' L'+coords[coords.length-1].x+' '+(h-bottom)+' L'+coords[0].x+' '+(h-bottom)+' Z',fill:'#eef5ff'}));drawing.appendChild(svg('path',{d:line,fill:'none',stroke:'#4387ed','stroke-width':2.4,'stroke-linejoin':'round','stroke-linecap':'round'}));}
        coords.forEach(function(c,i){var point=svg('circle',{cx:c.x,cy:c.y,r:values.length>35?2:3,fill:'#fff',stroke:'#4387ed','stroke-width':1.5});var tooltip=svg('title');tooltip.textContent=points[i].label+': '+(financial?money(points[i].amount_cents):fmt(points[i].count));point.appendChild(tooltip);drawing.appendChild(point);if(i===0||i===points.length-1||i%Math.max(1,Math.ceil(points.length/6))===0){var label=svg('text',{x:c.x,y:h-8,'text-anchor':'middle',fill:'#7b8ba0','font-size':10});label.textContent=points[i].label;drawing.appendChild(label);}});
        $('chart').replaceChildren(drawing);var rows=$('trend-data');rows.replaceChildren();points.forEach(function(p){var tr=el('tr');tr.append(el('td',p.label),el('td',financial?money(p.amount_cents):fmt(p.count)));rows.appendChild(tr);});
        if(!$('donut'))return;
        var total=data.completed,stop=0,gradient=[];$('channels').replaceChildren();data.channels.forEach(function(c){var percent=total?c.count/total*100:0;gradient.push(colors[c.key]+' '+stop+'% '+(stop+percent)+'%');stop+=percent;var row=el('div'),dot=el('span',undefined,'ho-dot');dot.style.background=colors[c.key];row.append(dot,el('span',text[c.key]),el('b',fmt(c.count)),el('small',fmt(percent)+'%'));$('channels').appendChild(row);});
        $('donut').style.background=total?'conic-gradient('+gradient.join(',')+')':'#edf1f7';$('completed').textContent=fmt(total);
    }
    function renderLive(){var n=$('live');n.replaceChildren();[['new','bell','blue'],['preparing','utensils','orange'],['courier','motorcycle','green'],['awaiting_payment','receipt','purple']].forEach(function(a){var row=el('div',undefined,'ho-live-card'),copy=el('div');copy.append(el('span',text[a[0]]+' · '+text.now),el('strong',fmt(data.active[a[0]])));row.append(icon(a[1],'ho-icon ho-'+a[2]),copy);n.appendChild(row);});}
    function renderStock(){
        if(data.owner_platform){renderOwnerStock();return;}
        var search=$('stock-search').value.trim().toLocaleLowerCase(),n=$('stock');n.replaceChildren();var items=data.inventory.items.filter(function(i){return i.name.toLocaleLowerCase().includes(search);});
        items.forEach(function(i){var row=el('tr'),name=el('td',i.name),qty=el('td',undefined,i.negative_branches?'ho-stock-warning':'');qty.appendChild(el('strong',i.tracked_branches?fmt(Number(i.quantity))+' '+text[i.unit==='kg'?'kg':'unit']:text.untracked));if(i.negative_branches)qty.appendChild(el('small',text.stock_negative_note));else if(i.tracked_branches&&i.tracked_branches<data.selected_branches)qty.appendChild(el('small',text.partial));row.append(name,qty,el('td',fmt(i.tracked_branches)+' / '+fmt(data.selected_branches)));n.appendChild(row);});
        if(!items.length){var tr=el('tr'),td=el('td',data.modules.inventory?text.no_stock:text.unavailable_inventory,'ho-empty');td.colSpan=3;tr.appendChild(td);n.appendChild(tr);}$('stock-count').textContent=fmt(data.inventory.tracked)+' / '+fmt(data.inventory.items.length);routeLink($('stock-link'),data.filters.branch);
    }
    function renderAlerts(){
        $('alert-count').textContent=fmt(data.alerts.length);var n=$('alerts');n.replaceChildren();data.alerts.forEach(function(a){var row=el(a.url?'a':'div',undefined,'ho-alert ho-alert-'+a.tone);if(a.url)row.href=safeLink(a.url);row.appendChild(icon(a.kind.includes('stock')?'boxes':a.kind==='print_pending'?'print':a.kind==='late_orders'?'clock':a.kind==='pending_expenses'?'file-invoice-dollar':'info-circle'));var copy=el('div');copy.appendChild(el('strong',text[a.kind]));if(a.name)copy.appendChild(el('span',a.name));if(a.branch)copy.appendChild(el('span',a.branch));if(a.value!==null&&a.value!==undefined)copy.appendChild(el('span',fmt(Number(a.value))+(a.unit?' '+text[a.unit==='kg'?'kg':'unit']:'')));row.appendChild(copy);n.appendChild(row);});if(!data.alerts.length)n.appendChild(el('p',text.no_alerts,'ho-empty'));
    }
    function renderRecent(){
        var n=$('recent'),names=new Map(data.branches.map(function(b){return [b.value,b.name];}));n.replaceChildren();data.recent.forEach(function(o){var row=el('article',undefined,'ho-order'),copy=el('div'),link=el('a',o.number),status=el('div');link.href=safeLink(o.url);link.setAttribute('aria-label',text.details+' '+o.number);copy.append(link,el('small',(text[o.channel]||o.channel)+' · '+(names.get(o.branch)||'')));status.append(badge(text[o.status]||o.status,o.status==='completed'?'green':o.status==='cancelled'?'red':o.status==='preparing'?'orange':'blue'),el('small',new Date(o.at).toLocaleTimeString(boot.locale==='ar'?'ar-EG':'en-GB',{hour:'2-digit',minute:'2-digit',timeZone:'Africa/Cairo'})));row.append(copy,status);n.appendChild(row);});if(!data.recent.length)n.appendChild(el('p',text.no_orders,'ho-empty'));routeLink($('orders-link'),data.filters.branch);
    }
    function stat(label,value,name,tone){var n=el('div',undefined,'ho-app-stat'),copy=el('div');copy.append(el('span',label),el('strong',fmt(value)));n.append(icon(name,'ho-icon ho-'+tone),copy);return n;}
    function renderApp(){
        var n=$('app');n.replaceChildren();n.append(stat(text.app_orders,data.modules.app?data.app_orders:null,'mobile-alt','blue'),stat(data.customers.global?text.new_customers:text.period_customers,data.customers.period,'user-plus','green'),stat(text.cancelled,data.cancelled,'times-circle','red'));
        if(data.customers.global&&data.customers.pending_partners!==null)n.appendChild(stat(text.pending_partners,data.customers.pending_partners,'user-clock','orange'));else n.appendChild(stat(text.tracked,data.inventory.tracked,'boxes','teal'));
    }
    function renderBranches(){
        var n=$('branches');n.replaceChildren();$('branch-count').textContent=fmt(data.branch_cards.length);$('branch-prev').hidden=data.branch_cards.length<2;$('branch-next').hidden=data.branch_cards.length<2;
        data.branch_cards.forEach(function(b){var card=el('article',undefined,'ho-branch'),header=el('header'),copy=el('div');copy.append(el('h3',b.name),badge(b.open===null?text.unknown:b.open?text.opened:text.closed,b.open?'green':'red'));header.append(copy,icon('store'));card.appendChild(header);var list=el('dl');[['new',b.active.new],['preparing',b.active.preparing],['courier',b.active.courier],['completed',b.completed]].forEach(function(a){var row=el('div');row.append(el('dt',text[a[0]]),el('dd',fmt(a[1])));list.appendChild(row);});if(b.sales)[['gross',b.sales.gross_cents],['approved_expenses',b.sales.expenses_cents]].forEach(function(a){var row=el('div');row.append(el('dt',text[a[0]]),el('dd',money(a[1])));list.appendChild(row);});if(data.owner_drawer&&data.owner_drawer.branches[b.value]){var cashRow=el('div');cashRow.append(el('dt',text.drawer),el('dd',money(data.owner_drawer.branches[b.value].expected_cents)));list.appendChild(cashRow);}card.appendChild(list);var button=el('button',text.branch_details);button.type='button';button.addEventListener('click',function(){form.elements.branch.value=b.value;refresh(true);root.scrollIntoView({behavior:'smooth',block:'start'});});card.appendChild(button);n.appendChild(card);});
        renderRanking();
    }
    function renderRanking(){
        var ranks=$('ranking'),max=Math.max(1,...data.ranking.map(function(r){return data.can_view_financials?r.amount_cents:r.count;}));ranks.replaceChildren();data.ranking.forEach(function(r,i){var row=el('div',undefined,'ho-rank'),copy=el('div'),track=el('div',undefined,'ho-rank-track'),bar=el('span');copy.append(el('i',r.sales_rank||i+1),el('span',r.name),el('b',data.can_view_financials?money(r.amount_cents):fmt(r.count)));bar.style.width=Math.max(0,(data.can_view_financials?r.amount_cents:r.count)/max*100)+'%';track.appendChild(bar);row.append(copy,track);ranks.appendChild(row);});
    }

    function amount(value,ready){return ready?money(value):'—';}
    function renderOwner(){
        var p=data.owner_platform,n=$('platform');n.replaceChildren();
        [['go_partners','handshake','blue','activated_accounts'],['go_stores','store','purple','activated_stores'],['go_users','users','blue','registered'],['fasakhansta_users','user-friends','orange','registered'],['fasakhansta_stores','store-alt','green','registered_stores']].forEach(function(a){
            var tile=card(text[a[0]],fmt(p[a[0]]),'',p[a[0]]===null?text.unavailable:text[a[3]],a[1],a[2]);tile.dataset.hoMetric=a[0];n.appendChild(tile);
        });
        var pending=card(text.pending_join,fmt(p.pending_total),'',p.pending_total===null?text.unavailable:text.pending_stores+': '+fmt(p.pending_stores)+' · '+text.pending_people+': '+fmt(p.pending_partners),'user-clock','orange');pending.dataset.hoMetric='pending_total';n.appendChild(pending);
        var salesReady=data.modules.pos&&data.modules.app;
        $('drawer-total').textContent=amount(data.owner_drawer.total_cents,data.owner_drawer.ready);
        $('expenses-total').textContent=amount(data.sales.expenses_cents,data.modules.expenses);
        var drawers=$('drawers'),expenses=$('branch-expenses'),rows=$('branch-sales');drawers.replaceChildren();expenses.replaceChildren();rows.replaceChildren();
        function branchName(b){var name=el('span',b.name,'ho-row-name');name.title=b.name;return name;}
        data.branch_cards.forEach(function(b){
            var cash=data.owner_drawer.branches[b.value],drawer=el('article',undefined,'ho-owner-row');drawer.dataset.hoBranch=b.value;
            drawer.append(branchName(b),el('strong',data.owner_drawer.ready&&cash?decimal(cash.expected_cents):'—'));drawers.appendChild(drawer);
            var expense=el('article',undefined,'ho-owner-row');expense.dataset.hoBranch=b.value;expense.append(branchName(b),el('strong',data.modules.expenses?decimal(b.sales.expenses_cents):'—'));expenses.appendChild(expense);
            var row=el('article',undefined,'ho-owner-row ho-sales-line');row.dataset.hoBranch=b.value;row.appendChild(branchName(b));
            ['takeaway','dine','phone','app'].forEach(function(c){var entry=b.sales.channels[c],ready=c==='app'?data.modules.app:data.modules.pos,cell=el('span',ready?decimal(entry.gross_cents):'—');cell.dataset.hoChannel=c;cell.title=text[c]+': '+amount(entry.gross_cents,ready)+' · '+fmt(entry.count)+' '+text.order;row.appendChild(cell);});
            row.appendChild(el('strong',salesReady?decimal(b.sales.gross_cents):'—'));rows.appendChild(row);
        });
        var footer=$('sales-footer');footer.replaceChildren(el('span',text.total));['takeaway','dine','phone','app'].forEach(function(c){footer.appendChild(el('strong',(c==='app'?data.modules.app:data.modules.pos)?decimal(data.sales.channels[c].gross_cents):'—'));});footer.appendChild(el('strong',salesReady?decimal(data.sales.gross_cents):'—'));
        if(!data.branch_cards.length)[drawers,expenses,rows].forEach(function(list){list.appendChild(el('p',text.no_branches,'ho-empty'));});
    }
    function renderOwnerStock(){
        var select=$('stock-ingredient'),selected=select.value,n=$('stock');select.replaceChildren();
        data.inventory.items.forEach(function(item){var option=el('option',item.name+' · '+text[item.unit==='kg'?'kg':'unit']);option.value=String(item.id);select.appendChild(option);});
        if(data.inventory.items.some(function(i){return String(i.id)===selected;}))select.value=selected;
        var item=data.inventory.items.find(function(i){return String(i.id)===select.value;});n.replaceChildren();
        if(item)data.branch_cards.forEach(function(b){var balance=item.balances[b.value],row=el('article',undefined,'ho-owner-row'),name=el('span',b.name,'ho-row-name');name.title=b.name;row.dataset.hoBranch=b.value;
            var qty=el('strong',balance?fmt(Number(balance.quantity))+' '+text[item.unit==='kg'?'kg':'unit']:text.untracked,balance&&balance.quantity_units<0?'ho-stock-warning':'');row.append(name,qty);n.appendChild(row);
        });
        else n.appendChild(el('p',data.modules.inventory?text.no_stock:text.unavailable_inventory,'ho-empty'));
        routeLink($('stock-link'),data.filters.branch);
    }
    function sizeOwner(){
        if(!data.owner_platform||disposed)return;
        var wrapper=root.closest('.ho-wrapper'),desktop=window.matchMedia('(min-width:1100px) and (min-height:700px)').matches;
        if(desktop){var footer=document.querySelector('.main-footer'),available=window.innerHeight-wrapper.getBoundingClientRect().top-(footer?footer.getBoundingClientRect().height:0);wrapper.style.setProperty('--ho-height',Math.max(520,available)+'px');}
        else wrapper.style.removeProperty('--ho-height');
        root.querySelectorAll('.ho-owner-list').forEach(function(list){if(list.clientHeight)list.style.setProperty('--ho-row-height',(list.clientHeight/10)+'px');});
        chart();
    }
    function queueOwnerSize(){if(!data.owner_platform||disposed)return;cancelAnimationFrame(ownerFrame);ownerFrame=requestAnimationFrame(sizeOwner);}

    function renderSummary(){var n=$('summary');n.hidden=!data.can_view_financials;n.replaceChildren();if(!data.can_view_financials)return;var s=data.sales;[['gross',s.gross_cents,'coins','blue'],['delivery',s.delivery_cents,'motorcycle','orange'],['approved_expenses',s.expenses_cents,'file-invoice-dollar','red'],['net',s.net_cents,'chart-line','green']].forEach(function(a){var box=el('div'),copy=el('div');copy.append(el('span',text[a[0]]),el('strong',money(a[1])));box.append(icon(a[2],'ho-icon ho-'+a[3]),copy);n.appendChild(box);});}
    function render(){
        $('updated').textContent=text.updated+' '+new Date(data.updated_at).toLocaleTimeString(boot.locale==='ar'?'ar-EG':'en-GB',{hour:'2-digit',minute:'2-digit',timeZone:'Africa/Cairo'});$('range').textContent=scopeLabel()+' · '+data.filters.from+' — '+data.filters.to;
        $('empty').hidden=!!data.selected_branches;$('content').hidden=!data.selected_branches&&!data.owner_platform;$('drawer-note').hidden=!data.owner_drawer;$('legacy').hidden=!(data.modules.app&&data.can_view_financials);
        if(data.owner_platform){renderOwner();chart();renderStock();renderRanking();queueOwnerSize();}
        else {renderKpis();chart();renderLive();renderStock();renderAlerts();renderRecent();renderApp();renderBranches();renderSummary();}
        if(!data.can_view_financials)note(text.cashier_note);
    }
    function periodButtons(){root.querySelectorAll('[data-ho-period]').forEach(function(b){b.setAttribute('aria-pressed',String(b.dataset.hoPeriod===period));});$('dates').hidden=period!=='custom';}
    function params(){var p={branch:form.elements.branch.value,period:period};if(period==='custom'){p.from=form.elements.from.value;p.to=form.elements.to.value;}return p;}
    async function refresh(changed){
        if(disposed)return;if(controller)controller.abort();controller=new AbortController();var current=++sequence,query=params(),at=controller;editing=false;
        if(changed){$('content').hidden=true;note(text.loading);}root.setAttribute('aria-busy','true');$('refresh').disabled=true;
        var timeout=setTimeout(function(){at.abort();},15000);
        try{var url=new URL(boot.url,location.href);if(url.origin!==location.origin)throw Error(text.failed);Object.keys(query).forEach(function(k){url.searchParams.set(k,query[k]);});var response=await fetch(url.href,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},signal:at.signal});var result;try{result=await response.json();}catch(_){throw Error(text.failed);}if(!response.ok||!result.success)throw Error(result.message||text.failed);if(disposed||sequence!==current)return;
            data=result;lastLoaded=Date.now();note('');render();form.elements.from.value=data.filters.from;form.elements.to.value=data.filters.to;var locationUrl=new URL(location.href);['branch','period','from','to'].forEach(function(k){locationUrl.searchParams.delete(k);});Object.keys(query).forEach(function(k){if(query[k])locationUrl.searchParams.set(k,query[k]);});history.replaceState(history.state,'',locationUrl.href);
        }catch(error){if(disposed||sequence!==current)return;note(changed?(error.name==='AbortError'?text.failed:error.message):text.stale);if(changed)$('content').hidden=true;}
        finally{clearTimeout(timeout);if(!disposed&&sequence===current){root.removeAttribute('aria-busy');$('refresh').disabled=false;controller=null;}}
    }
    form.elements.branch.value=data.filters.branch;form.elements.from.value=data.filters.from;form.elements.to.value=data.filters.to;var today=new Date().toLocaleDateString('en-CA',{timeZone:'Africa/Cairo'});form.elements.from.max=today;form.elements.to.max=today;
    root.querySelectorAll('[data-ho-period]').forEach(function(b){on(b,'click',function(){period=b.dataset.hoPeriod;periodButtons();if(period==='custom'){editing=true;if(controller)controller.abort();sequence++;$('refresh').disabled=false;root.removeAttribute('aria-busy');}else refresh(true);});});
    on(form,'submit',function(event){event.preventDefault();if(form.reportValidity())refresh(true);});on(form.elements.branch,'change',function(){refresh(true);});on(form.elements.from,'input',editDates);on(form.elements.to,'input',editDates);on($('refresh'),'click',function(){if(form.reportValidity())refresh(false);});on($('stock-search'),'input',renderStock);on($('stock-ingredient'),'change',renderStock);
    function editDates(){editing=true;if(controller){controller.abort();controller=null;sequence++;$('refresh').disabled=false;root.removeAttribute('aria-busy');}}
    ['prev','next'].forEach(function(direction){on($('branch-'+direction),'click',function(){var list=$('branches'),sign=(root.dir==='rtl'?-1:1)*(direction==='next'?1:-1);list.scrollBy({left:sign*(list.clientWidth-24),behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});});});
    function automatic(){if(!disposed&&!document.hidden&&!editing&&!controller&&Date.now()-lastLoaded>45000)refresh(false);}
    on(document,'visibilitychange',automatic);on(window,'focus',automatic);timer=setInterval(automatic,60000);
    if(data.owner_platform){on(window,'resize',queueOwnerSize);ownerObserver=new ResizeObserver(queueOwnerSize);root.querySelectorAll('.ho-owner-list,[data-ho-chart]').forEach(function(n){ownerObserver.observe(n);});}
    if(window.DashboardSPA)window.DashboardSPA.onCleanup(function(){disposed=true;sequence++;if(controller)controller.abort();clearInterval(timer);if(ownerObserver)ownerObserver.disconnect();cancelAnimationFrame(ownerFrame);listeners.forEach(function(off){off();});});
    periodButtons();render();
}());
