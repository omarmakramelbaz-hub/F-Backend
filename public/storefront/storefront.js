(() => {
'use strict';
const $ = (s, root = document) => root.querySelector(s);
const data = JSON.parse($('#storefront-data').textContent);
const placeholder = '/dashboard/branding/fasakhansta-logo-transparent.png';
const storageKey = 'fasakhansta.web.cart.v1';
const pendingKey = 'fasakhansta.web.pending.v1';
const customerKey = 'fasakhansta.web.customer.v1';
const readStorage = key => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { return null; } };
const writeStorage = (key, value) => { try { value === null ? sessionStorage.removeItem(key) : sessionStorage.setItem(key, JSON.stringify(value)); } catch (_) {} };
let restorable = readStorage(storageKey);
// Eligibility is discovered by the server only after delivery details are confirmed.
data.branches = [];
const state = { context: null, discovering: false, branch: '', products: [], categories: [], cart: [], category: '', search: '', limit: 12, menuReady: false, storeFilter: 'all', storeSearch: '', storeLimit: 12, open: false, quote: null, point: null, submitting: false, pending: readStorage(pendingKey) };
let menuRequest, map, marker, suggestionRequest, suggestionTimer, toastTimer, pendingBranch, pendingAddress = null, quoteRequest = 0;
let customerProfile = readCustomer(), selectedAddress = '', locationRevision = 0, geolocationRequest = 0;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = value => new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 }).format(Number(value || 0));
const imageUrl = value => { try { const url = new URL(value, location.origin); return /^https?:$/.test(url.protocol) ? url.href : placeholder; } catch (_) { return placeholder; } };
const branch = () => data.branches.find(b => String(b.id) === state.branch);
const unitName = mode => mode === 'weight' ? 'كجم' : 'قطعة';
const normalized = value => String(value).replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').toLowerCase();
const quantity = value => Number(Number(value).toFixed(3));
const total = () => state.cart.reduce((sum, line) => sum + Math.round(line.price * 100) * line.quantity, 0) / 100;
function readCustomer() {
 try {
  const p = JSON.parse(localStorage.getItem(customerKey) || 'null');
  if (!p || p.version !== 1 || typeof p.customer_name !== 'string' || p.customer_name.trim().length < 2 || p.customer_name.length > 100 || typeof p.customer_phone !== 'string' || !/^(?:\+?20|0)1[0125]\d{8}$/.test(p.customer_phone) || !Array.isArray(p.addresses)) return null;
  const ids = new Set();
  const addresses = p.addresses.slice(0,10).filter(a => {
   if (!a || typeof a.id !== 'string' || !/^[a-zA-Z0-9-]{1,80}$/.test(a.id) || ids.has(a.id) || typeof a.area !== 'string' || a.area.trim().length < 2 || a.area.length > 150 || typeof a.address !== 'string' || a.address.trim().length < 8 || a.address.length > 500 || typeof a.latitude !== 'number' || typeof a.longitude !== 'number' || !Number.isFinite(a.latitude) || !Number.isFinite(a.longitude) || a.latitude < 22 || a.latitude > 32 || a.longitude < 24 || a.longitude > 37) return false;
   ids.add(a.id); return true;
  }).map(({id,area,address,latitude,longitude}) => ({id,area,address,latitude,longitude}));
  return addresses.length ? {version:1,customer_name:p.customer_name.trim(),customer_phone:p.customer_phone,selected_id:addresses.some(a=>a.id===p.selected_id)?p.selected_id:addresses[0].id,addresses} : null;
 } catch (_) { return null; }
}
function writeCustomer(profile) {
 try { profile ? localStorage.setItem(customerKey,JSON.stringify(profile)) : localStorage.removeItem(customerKey); return true; } catch (_) { return false; }
}
function renderSavedAddresses() {
 const select = $('#saved-address-select'); select.replaceChildren();
 $('#saved-customer').hidden = !customerProfile;
 $('.site-header').classList.toggle('has-saved-address',!!customerProfile);
 $('#location-shortcut').hidden = !!customerProfile;
 $('#forget-customer').hidden = !customerProfile;
 if (!customerProfile) return;
 for (const a of customerProfile.addresses) {
  const option = document.createElement('option'); option.value=a.id; option.textContent=`${a.area} — ${a.address}`; select.append(option);
 }
 const add = document.createElement('option'); add.value='new'; add.textContent='+ أضف عنوان جديد'; select.append(add);
 select.value = selectedAddress || 'new';
 select.disabled = state.discovering || state.submitting || !!state.pending;
}
function restoreAddress(id) {
 const a = customerProfile?.addresses.find(a=>a.id===id); if(!a)return;
 selectedAddress=id;
 const form=$('#delivery-form');form.elements.customer_name.value=customerProfile.customer_name;form.elements.customer_phone.value=customerProfile.customer_phone;
 form.elements.area.value=a.area;form.elements.address.value=a.address;
 clearTimeout(suggestionTimer);suggestionRequest?.abort();$('#address-suggestions').replaceChildren();
 // Initial address recall is independent of the sealed pending-order retry payload.
 form.elements.location_confirmed.checked=setPoint(a.latitude,a.longitude,true,true);
 $('#location-help').textContent='ده موقع العنوان المحفوظ. تقدر تختار عنوان تاني أو تعدّل الدبوس.';
 renderSavedAddresses();
}
function showSavedSummary() {
 const form=$('#delivery-form');
 $('#delivery-address').textContent=`${form.elements.address.value}، ${form.elements.area.value}`;
 $('#delivery-phone').textContent=form.elements.customer_phone.value;
 form.hidden=true;$('#delivery-summary').hidden=false;
}
function applyAddress(id) {
 clearTimeout(suggestionTimer);suggestionRequest?.abort();$('#address-suggestions').replaceChildren();
 document.querySelectorAll('dialog[open]').forEach(closeDialog);
 resetDelivery(false);
 if(id==='new'){
  selectedAddress='';locationRevision++;state.point=null;
  const form=$('#delivery-form');form.elements.area.value='';form.elements.address.value='';form.elements.location_confirmed.checked=false;
  initMap();renderSavedAddresses();locateCustomer();focusDelivery();form.elements.area.focus({preventScroll:true});return;
 }
 restoreAddress(id);discoverDelivery(true);
}
function changeAddress(id) {
 if(state.discovering||state.submitting||state.pending){renderSavedAddresses();return;}
 if(id===selectedAddress&&state.context)return;
 if(state.cart.length){pendingAddress=id;renderSavedAddresses();showDialog('#change-location-dialog');return;}
 applyAddress(id);
}
function saveCustomer(context, name) {
 const prior=readCustomer();
 // Merge only the same customer's addresses; never expose a phone lookup endpoint.
 const addresses=prior?.customer_phone===context.customer_phone?[...prior.addresses]:[];
 const same=a=>normalized(a.area).trim()===normalized(context.area).trim()&&normalized(a.address).trim()===normalized(context.address).trim()&&Math.abs(a.latitude-context.latitude)<.0001&&Math.abs(a.longitude-context.longitude)<.0001;
 const existing=addresses.find(same);
 const address={id:existing?.id||uuid(),area:context.area,address:context.address,latitude:context.latitude,longitude:context.longitude};
 customerProfile={version:1,customer_name:name.trim(),customer_phone:context.customer_phone,selected_id:address.id,addresses:[address,...addresses.filter(a=>a.id!==address.id)].slice(0,10)};
 selectedAddress=address.id;const saved=writeCustomer(customerProfile);renderSavedAddresses();
 if(!saved)notify('المتصفح منع حفظ البيانات. تقدر تكمل طلبك، لكن هتحتاج تدخل بياناتك المرة الجاية.');
}
function persist() { writeStorage(storageKey, { branch: state.branch, cart: state.cart.map(({productId, optionId, mode, quantity}) => ({productId, optionId, mode, quantity})) }); }
function uuid() {
 if (crypto.randomUUID) return crypto.randomUUID();
 return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
}
function notify(message) { clearTimeout(toastTimer); $('#toast').textContent = message; $('#toast').hidden = false; toastTimer = setTimeout(() => $('#toast').hidden = true, 3000); }
function showDialog(id) { const dialog = $(id); if (!dialog.open) dialog.showModal(); document.body.classList.add('dialog-open'); }
function closeDialog(dialog) { if (state.submitting) return; dialog.close(); if (!document.querySelector('dialog[open]')) document.body.classList.remove('dialog-open'); }
async function api(name, values = {}, signal) {
 const post = ['branches','addresses','quote','checkout'].includes(name);
 const url = new URL(data.endpoints[name], location.origin);
 if (!post) Object.entries(values).forEach(([key, value]) => url.searchParams.set(key, value));
 const response = await fetch(url, { method: post ? 'POST' : 'GET', credentials: 'same-origin', signal, headers: { 'Accept': 'application/json', ...(post ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content } : {}) }, ...(post ? { body: JSON.stringify(values) } : {}) });
 let result; try { result = await response.json(); } catch (_) { result = {}; }
 if (!response.ok) {
  const fields = Object.values(result.errors || {}).flat();
  const message = response.status === 419 ? 'انتهت الجلسة. حدّث الصفحة وحاول تاني.' : response.status === 429 ? 'طلبات كتير في وقت قصير. استنى لحظات وحاول تاني.' : response.status >= 500 ? 'الخدمة غير متاحة للحظات. حاول تاني أو تواصل مع المتجر.' : fields[0] || result.message || 'تعذر تنفيذ العملية. حاول مرة أخرى.';
  const error = new Error(message); error.status = response.status; throw error;
 }
 return result;
}
function updateCartBadge() {
 const count = state.cart.length;
 $('#cart-count').textContent = count;
 $('#mobile-count').textContent = count;
 $('#mobile-total').textContent = `${money(total())} ج.م`;
 $('#mobile-cart').hidden = !count;
 persist();
}
const physicalBranches = new Set([94,301,307,363]);
function renderBranches() {
 const select = $('#branch-select');
 select.replaceChildren();
 const placeholderOption = document.createElement('option'); placeholderOption.value='';
 placeholderOption.textContent=!state.context?'حدد عنوانك الأول':data.branches.length?'اختار متجرك لعرض المنيو':'مفيش متجر بيغطي موقعك حاليًا';
 select.append(placeholderOption);select.disabled=!state.context||!data.branches.length;
 data.branches.forEach(b=>{const option=document.createElement('option');option.value=String(b.id);option.textContent=`${b.name} — ${money(b.distance_km)} كم${b.open?'':' — غير متاح الآن'}`;select.append(option);});
 select.value=state.branch;
 $('#store-tools').hidden=!state.context||!data.branches.length;
 renderStoreCards();
}
function renderStoreCards() {
 const list=data.branches.filter(b=>(state.storeFilter==='all'||physicalBranches.has(Number(b.id))===(state.storeFilter==='branches'))&&(!state.storeSearch||normalized(`${b.name} ${b.address||''}`).includes(normalized(state.storeSearch))));
 $('#store-count').textContent=!state.context?'حدد عنوانك علشان تشوف المتاجر القريبة اللي بتخدمك.':`${money(list.length)} متجر وفرع${state.storeSearch?' يطابق بحثك':' بيغطي مكانك — من الأقرب للأبعد'}`;
 $('#show-more-stores').hidden=list.length<=state.storeLimit;
 let empty=!state.context?'حدد موقعك وعنوانك ورقمك الأول، وهنعرض لك المتاجر اللي بتخدمك.':!data.branches.length?'مفيش متجر بيغطي مكان التوصيل ده حاليًا. تقدر تغيّر العنوان أو الموقع وتشوف الخدمة.':'ملقيناش متجر بالاسم أو التصنيف ده. جرّب بحث تاني.';
 $('#branch-cards').innerHTML = list.length ? list.slice(0,state.storeLimit).map(b => `<article class="branch-card"><div class="store-card-top"><span class="store-avatar">${b.logo ? `<img src="${esc(imageUrl(b.logo))}" alt="" width="46" height="46" loading="lazy">` : esc(b.name.replace(/^(?:فرع|فسخانستا)\s*/,'').slice(0,2))}</span><div><h3>${esc(b.name)}</h3><span class="store-kind">${physicalBranches.has(Number(b.id))?'فرع فسخانستا':'متجر فسخانستا'} · ${money(b.distance_km)} كم</span></div></div><span class="open-status ${b.open ? '' : 'closed'}">${b.open ? 'متاح للطلب الآن' : b.status === 'busy' ? 'مشغول حاليًا' : 'مغلق حاليًا'}</span><p>${esc(b.address || 'شوف منيو المتجر وابدأ طلبك.')}</p><div class="branch-links"><button class="text-link" data-action="branch" data-id="${b.id}">شوف المنيو ←</button>${/^[+0-9 ()-]{6,30}$/.test(b.phone) ? `<a dir="ltr" href="tel:${esc(b.phone.replace(/[ ()-]/g,''))}">${esc(b.phone)}</a>` : ''}</div></article>`).join('') : `<div class="muted store-empty"><p>${esc(empty)}</p>${!state.context||!data.branches.length?'<button class="button secondary" data-action="edit-location">حدد مكان التوصيل ←</button>':''}</div>`;
 document.querySelectorAll('[data-action="store-filter"]').forEach(button=>{const active=button.dataset.filter===state.storeFilter;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));});
}
function focusDelivery() {
 document.getElementById('delivery').scrollIntoView({behavior:'smooth'});
 if(!state.context){if($('#delivery-form').hidden&&customerProfile){$('#saved-address-select').focus({preventScroll:true});return;}initMap();$('#delivery-form').elements.customer_phone.focus({preventScroll:true});}
 else {document.getElementById('menu').scrollIntoView({behavior:'smooth'});$('#branch-select').focus({preventScroll:true});}
}
function resetDelivery(scroll=true) {
 menuRequest?.abort();state.context=null;data.branches=[];state.branch='';state.cart=[];state.products=[];state.categories=[];state.menuReady=false;state.quote=null;state.open=false;restorable=null;
 state.storeSearch='';state.storeFilter='all';state.storeLimit=12;$('#store-search').value='';
 $('#product-grid').innerHTML='';$('#categories').innerHTML='';$('#show-more').hidden=true;$('#header-branch').textContent='حدد عنوانك';
 $('#delivery-form').hidden=false;$('#delivery-summary').hidden=true;$('#delivery-error').textContent='';$('#delivery-form').elements.location_confirmed.checked=false;
 $('#saved-address-status').textContent='';$('#retry-saved-address').hidden=true;
 renderBranches();updateCartBadge();status('حدد مكان التوصيل الأول','هنراجع المتاجر اللي بتغطي العنوان والموقع الجديد.');if(scroll)focusDelivery();
}
function editLocation() {
 if(state.submitting||state.discovering)return;
 if(state.pending){notify('نتأكد من الطلب السابق الأول قبل تغيير العنوان.');return openCart();}
 if(state.cart.length){pendingAddress=null;showDialog('#change-location-dialog');return;}
 document.querySelectorAll('dialog[open]').forEach(closeDialog);
 resetDelivery();
}

function status(title, message, retry = false) {
 $('#menu-status').hidden = false;
 $('#menu-status').innerHTML = `<span class="empty-symbol" aria-hidden="true">${retry ? '↻' : '⌖'}</span><h3>${esc(title)}</h3><p>${esc(message)}</p>${retry ? '<button class="button secondary" style="margin-top:18px" data-action="retry-menu">حاول تاني</button>' : !state.context?'<button class="button secondary" data-action="edit-location">حدد عنوانك ←</button>':''}`;
}
function renderCategories() {
 const activeIds = new Set(state.products.map(p => String(p.category_id)));
 const categories = state.categories.filter(c => activeIds.has(String(c.id)));
 $('#categories').innerHTML = [{id:'',name:'كل المنيو'},...categories].map(c => `<button class="category ${String(c.id) === state.category ? 'active' : ''}" aria-pressed="${String(c.id) === state.category}" data-action="category" data-id="${c.id}">${esc(c.name)}</button>`).join('');
}
function renderMenu() {
 if (!state.menuReady) return;
 renderCategories();
 const list = state.products.filter(p => (!state.category || String(p.category_id) === state.category) && (!state.search || normalized(p.name).includes(normalized(state.search))));
 $('#product-grid').innerHTML = list.slice(0, state.limit).map(product => {
  const category = state.categories.find(c => String(c.id) === String(product.category_id));
  const image = product.image_url ? `<img src="${esc(imageUrl(product.image_url))}" alt="${esc(product.name)}" loading="lazy" width="480" height="330">` : `<span class="product-placeholder"><img src="${placeholder}" alt="" width="90" height="90"></span>`;
  return `<article class="product-card"><button class="product-image-button" data-action="product" data-id="${product.id}" aria-label="تفاصيل ${esc(product.name)}">${image}${category ? `<span class="product-badge">${esc(category.name)}</span>` : ''}</button><div class="product-body"><h3>${esc(product.name)}</h3><p class="product-description">${product.options.length ? 'اختار الحجم والتجهيز على مزاجك' : product.quantity_mode === 'weight' ? 'اختار الوزن المناسب ليك' : 'من اختيارات منيو المتجر'}</p><div class="product-bottom"><span class="price">${money(product.price)}<small>ج.م${product.unit === 'kg' ? ' / كجم' : ''}</small></span><button class="add-product" data-action="product" data-id="${product.id}" aria-label="أضف ${esc(product.name)} إلى السلة">+</button></div></div></article>`;
 }).join('');
 $('#show-more').hidden = list.length <= state.limit;
 if (!list.length) status(state.products.length ? 'ملقيناش الصنف ده' : 'المنيو مش متاح حاليًا', state.products.length ? 'جرّب اسم تاني أو اختار قسم مختلف.' : 'ممكن تختار متجر تاني أو تحاول بعد شوية.');
 else $('#menu-status').hidden = true;
 if (state.products.length && !state.open) status('المتجر غير متاح للطلب حاليًا', 'تقدر تتصفح المنيو، أو تختار متجر تاني متاح للطلب.');
}
async function loadBranch(id, restored = []) {
 if(!state.context||!data.branches.some(b=>String(b.id)===String(id)))return focusDelivery();
 menuRequest?.abort(); menuRequest = new AbortController();
 state.branch = String(id); state.cart = []; state.products = []; state.categories = []; state.category = ''; state.menuReady = false; state.quote = null; state.limit = 12;
 $('#branch-select').value = state.branch; $('#header-branch').textContent = branch()?.name || 'اختار متجرك'; $('#product-grid').innerHTML = ''; $('#categories').innerHTML = ''; $('#show-more').hidden = true;
 updateCartBadge();
 if (!id) return status('ابدأ باختيار متجرك', 'علشان تشوف الأصناف والأسعار المتاحة عندك.');
 status('بنجهّزلك المنيو…', 'لحظات وهتظهر أصناف وأسعار المتجر.');
 try {
  const menu = await api('catalog', {branch_id:id}, menuRequest.signal);
  if (state.branch !== String(id)) return;
  state.products = menu.products.map(p => ({...p, options: Array.isArray(p.options) ? p.options : []})); state.categories = menu.categories; state.open = menu.open; state.menuReady = true;
  for (const line of Array.isArray(restored) ? restored.slice(0,40) : []) {
   const p = state.products.find(p => p.id === Number(line.productId));
   const option = p?.options.find(o => o.id === line.optionId);
   if (!p || (line.optionId && !option) || !['weight','piece'].includes(line.mode) || !Number.isFinite(line.quantity) || line.quantity <= 0 || line.quantity > 99 || (line.mode === 'piece' && !Number.isInteger(line.quantity)) || (p.quantity_mode !== 'select' && p.quantity_mode !== line.mode)) continue;
   state.cart.push({...line, productId:p.id, name:p.name, image:p.image_url, label:option?.label || '', price:Number(option?.price ?? p.price)});
  }
  renderMenu(); updateCartBadge();
 } catch (error) { if (error.name !== 'AbortError') status('تعذر تحميل المنيو', error.message, true); }
}
function chooseBranch(id) {
 if(!state.context)return focusDelivery();
 if(!id||!data.branches.some(b=>String(b.id)===String(id))){$('#branch-select').value=state.branch;return;}
 if (String(id) === state.branch) return;
 if (state.pending) { $('#branch-select').value = state.branch; return openCart(); }
 if (state.cart.length) { pendingBranch = String(id); $('#branch-select').value = state.branch; showDialog('#confirm-branch-dialog'); }
 else {const restored=String(restorable?.branch)===String(id)?restorable.cart:[];restorable=null;loadBranch(id,restored);}
}
function productDialog(id) {
 const p = state.products.find(p => p.id === Number(id)); if (!p) return;
 const options = [{id:'', label:'الاختيار الأساسي', price:p.price}, ...p.options];
 const mode = p.quantity_mode === 'weight' || (p.quantity_mode === 'select' && /كيلو|كجم/.test(p.name)) ? 'weight' : 'piece';
 $('#product-detail').innerHTML = `<form class="product-detail" id="add-product-form" data-id="${p.id}"><img class="product-detail-image" src="${esc(imageUrl(p.image_url || placeholder))}" alt="${esc(p.name)}"><div class="product-detail-body"><span class="eyebrow">${esc(branch()?.name || '')}</span><h2 id="product-title">${esc(p.name)}</h2><span class="price" id="detail-price">${money(p.price)} <small>ج.م</small></span><label ${options.length === 1 ? 'hidden' : ''}>الحجم والتجهيز<select name="option">${options.map(o => `<option value="${esc(o.id)}">${esc(o.label)} — ${money(o.price)} ج.م</option>`).join('')}</select></label><label ${p.quantity_mode !== 'select' ? 'hidden' : ''}>وحدة الطلب<select name="mode"><option value="piece" ${mode === 'piece' ? 'selected' : ''}>بالقطعة / بالوجبة</option><option value="weight" ${mode === 'weight' ? 'selected' : ''}>بالكيلو</option></select></label><label><span id="detail-quantity-label">${mode === 'weight' ? 'الوزن بالكيلو' : 'العدد'}</span><input name="quantity" type="number" inputmode="decimal" min="${mode === 'weight' ? '.001' : '1'}" max="99" step="${mode === 'weight' ? '.001' : '1'}" value="1" required></label><p class="muted" style="margin-top:9px" id="detail-note">${mode === 'weight' ? 'مثال: 0.25 = ربع كيلو، 0.5 = نصف كيلو' : 'الكمية من الاختيار اللي حددته'}</p><button class="button primary wide" ${!state.open ? 'disabled' : ''}>${state.open ? 'أضف للسلة' : 'المتجر غير متاح الآن'} <span id="detail-total">${money(p.price)} ج.م</span></button></div></form>`;
 showDialog('#product-dialog');
 const form = $('#add-product-form');
 function update() { const option = options.find(o => o.id === form.elements.option.value); const mode = form.elements.mode.value; const q = form.elements.quantity; q.step = mode === 'weight' ? '.001' : '1'; q.min = mode === 'weight' ? '.001' : '1'; $('#detail-quantity-label').textContent = mode === 'weight' ? 'الوزن بالكيلو' : 'العدد'; $('#detail-note').textContent = mode === 'weight' ? 'مثال: 0.25 = ربع كيلو، 0.5 = نصف كيلو' : 'الكمية من الاختيار اللي حددته'; $('#detail-price').textContent = `${money(option.price)} ج.م`; $('#detail-total').textContent = `${money(Number(option.price) * Number(q.value))} ج.م`; }
 form.addEventListener('input',update); form.addEventListener('change',update);
 form.addEventListener('submit',event => {
  event.preventDefault(); if (!state.open || state.pending) return;
  const option = options.find(o => o.id === form.elements.option.value); const mode = form.elements.mode.value; const q = quantity(form.elements.quantity.value);
  if (!Number.isFinite(q) || q <= 0 || q > 99 || (mode === 'piece' && !Number.isInteger(q))) return;
  const old = state.cart.find(line => line.productId === p.id && line.optionId === option.id && line.mode === mode);
  if (old) { if (old.quantity + q > 99) return notify('الكمية القصوى للصنف ٩٩.'); old.quantity = quantity(old.quantity + q); }
  else { if (state.cart.length >= 40) return notify('الحد الأقصى ٤٠ صنف في الطلب.'); state.cart.push({productId:p.id,name:p.name,image:p.image_url,optionId:option.id,label:option.label === 'الاختيار الأساسي' ? '' : option.label,mode,quantity:q,price:Number(option.price)}); }
  state.quote = null; updateCartBadge(); closeDialog($('#product-dialog')); notify('اتضاف لسلتك. جهّز اللمة!');
 });
}
function openCart() {
 renderCart(); showDialog('#cart-dialog');
}
function renderCart() {
 const content = $('#cart-content'); const footer = $('#cart-footer');
 if (state.pending) { content.innerHTML = '<div class="empty-cart"><span class="empty-symbol">↻</span><h3>نتأكد من طلبك الأول</h3><p>في محاولة تأكيد سابقة. اضغط علشان نتأكد من تسجيلها من غير تكرار الطلب.</p></div>'; footer.innerHTML = '<button class="button primary wide" data-action="resume-checkout">تأكيد حالة الطلب السابق</button>'; return; }
 if (!state.cart.length) { content.innerHTML = '<div class="empty-cart"><span class="empty-symbol" aria-hidden="true">＋</span><h3>سلتك مستنية اللمة</h3><p>اختار من المنيو الأكلات اللي بتحبها.</p></div>'; footer.innerHTML = '<button class="button primary wide" data-action="back-menu">ارجع للمنيو ←</button>'; return; }
 content.innerHTML = `<p class="cart-branch">طلبك من ${esc(branch()?.name || '')}</p>` + state.cart.map((line,i) => `<article class="cart-line"><img src="${esc(imageUrl(line.image || placeholder))}" alt="" width="55" height="55"><div><h3>${esc(line.name)}</h3><p>${esc(line.label || (line.mode === 'weight' ? 'بالكيلو' : 'الاختيار الأساسي'))}</p><div class="line-controls"><button data-action="quantity" data-index="${i}" data-delta="1" aria-label="زود كمية ${esc(line.name)}">+</button><span>${money(line.quantity)} ${unitName(line.mode)}</span><button data-action="quantity" data-index="${i}" data-delta="-1" aria-label="قلل كمية ${esc(line.name)}">−</button><button class="remove-item" data-action="remove" data-index="${i}">حذف</button></div></div><span class="line-total">${money(line.price * line.quantity)}<small> ج.م</small></span></article>`).join('');
 footer.innerHTML = `<div class="cart-totals"><span>قيمة المنتجات</span><span>${money(total())} ج.م</span></div><p class="cart-disclaimer">هنحسب التوصيل وأي ضريبة مطبقة بعد تحديد عنوانك، وقبل ما تأكد الطلب.</p><button class="button primary wide" data-action="checkout" ${!state.open ? 'disabled' : ''}>${state.open ? 'كمل بيانات التوصيل ←' : 'المتجر غير متاح حاليًا'}</button>`;
}
function initMap() {
 if (!window.L) { $('#location-help').textContent = 'الخريطة تعذر تحميلها. تقدر تستخدم زر موقعي لتحديد مكانك.'; return; }
 if (!map) {
  map = L.map('delivery-map',{scrollWheelZoom:false}).setView([30.8,31.3],8);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'}).addTo(map);
  map.on('click', e => setPoint(e.latlng.lat,e.latlng.lng));
 } else if (!state.point) { map.setView([30.8,31.3],8); if (marker) { map.removeLayer(marker); marker=null; } }
 setTimeout(() => map.invalidateSize(),100);
}
function setPoint(lat,lng,zoom,restoring=false) {
 if(state.discovering||state.submitting||(state.pending&&!restoring))return false;
 lat=Number(lat);lng=Number(lng);
 if(!Number.isFinite(lat)||!Number.isFinite(lng))return false;
 if (lat < 22 || lat > 32 || lng < 24 || lng > 37) { $('#location-help').textContent = 'حدد مكان التوصيل داخل مصر.'; return false; }
 locationRevision++;
 state.point = {latitude:Number(lat.toFixed(7)),longitude:Number(lng.toFixed(7))}; state.quote = null;
 $('#delivery-form').elements.location_confirmed.checked = false;
 $('#location-help').textContent = 'تم تحديد المكان. حرّك الدبوس لمدخل العمارة، وبعدها أكد الموقع.';
 if (map && window.L) {
  if (!marker) { marker = L.marker([lat,lng],{draggable:true,icon:L.divIcon({className:'storefront-map-pin',html:'<span style="display:block;width:25px;height:25px;background:#fd7201;border:3px solid white;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 2px 8px #0005"></span>',iconSize:[25,25],iconAnchor:[12,25]})}).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); setPoint(p.lat,p.lng); }); }
  else marker.setLatLng([lat,lng]);
  if (zoom) map.setView([lat,lng],16);
 }
 return true;
}
function locateCustomer() {
 if(state.discovering||state.submitting||state.pending||state.context)return;
 if(!navigator.geolocation){$('#location-help').textContent='المتصفح لا يدعم تحديد الموقع. اختار المكان على الخريطة.';return;}
 const revision=locationRevision,request=++geolocationRequest;
 $('#location-help').textContent='جارٍ تحديد موقعك تلقائيًا… اسمح بالموقع لو المتصفح طلب الإذن.';
 navigator.geolocation.getCurrentPosition(p=>{
  if(request!==geolocationRequest||revision!==locationRevision||state.context||state.discovering||state.pending)return;
  if(!setPoint(p.coords.latitude,p.coords.longitude,true))return;
  const precise=!Number.isFinite(p.coords.accuracy)||p.coords.accuracy<=250;
  $('#delivery-form').elements.location_confirmed.checked=precise;
  $('#location-help').textContent=precise?'تم تحديد موقعك تلقائيًا. اكتب عنوان العمارة والدور والشقة، أو عدّل الدبوس لو التوصيل لمكان تاني.':'الموقع اللي رجع من جهازك تقريبي. حرّك الدبوس لمكان التوصيل وأكد الموقع.';
 },error=>{
  if(request!==geolocationRequest||revision!==locationRevision||state.context||state.discovering||state.pending)return;
  $('#location-help').textContent=error?.code===1?'إذن الموقع مقفول. اسمح بالموقع من إعدادات المتصفح، أو اختار مكان التوصيل على الخريطة.':'تعذر تحديد موقعك تلقائيًا. جرّب تحديث الموقع أو اختار مكان التوصيل على الخريطة.';
 },{enableHighAccuracy:true,timeout:12000,maximumAge:0});
}
function openCheckout() {
 if (state.pending) return resumeCheckout();
 if(!state.context)return focusDelivery();
 if (!state.cart.length || !state.menuReady || !state.open) return;
 closeDialog($('#cart-dialog')); state.quote = null; $('#checkout-form').hidden = false; $('#review-panel').hidden = true; $('#checkout-error').textContent = ''; $('#checkout-title').textContent = 'بيانات التوصيل.';
 for(const key of ['customer_phone','address','area'])$('#checkout-form').elements[key].value=state.context[key];
 $('#checkout-form').elements.customer_name.value=$('#delivery-form').elements.customer_name.value.trim();
 showDialog('#checkout-dialog');
}
function cartItems() { return state.cart.map(line => ({product_id:line.productId,option_id:line.optionId,quantity_mode:line.mode,quantity:line.quantity.toFixed(3)})); }
function renderReview(result, fields) {
 const q = result.summary;
 $('#checkout-form').hidden = true; $('#review-panel').hidden = false; $('#checkout-title').textContent = 'راجع طلبك.';
 $('#review-panel').innerHTML = `<div class="review-customer"><strong>${esc(fields.customer_name)}</strong> · ${esc(fields.customer_phone)}<br>${esc(fields.address)}، ${esc(fields.area)}<br><span class="muted">${esc(branch()?.name || '')} · مسافة التوصيل ${money(result.distance_km)} كم</span></div>${q.items.map(line => `<div class="review-row"><span>${esc(line.name)}${line.option_label ? ' — '+esc(line.option_label) : ''}<small class="muted"> × ${money(line.quantity)} ${unitName(line.quantity_mode)}</small></span><b>${money(line.total)} ج.م</b></div>`).join('')}<hr style="border:0;border-top:1px solid var(--line)"><div class="review-row"><span>المنتجات</span><span>${money(q.subtotal)} ج.م</span></div><div class="review-row"><span>التوصيل</span><span>${money(q.delivery)} ج.م</span></div>${Number(q.tax) ? `<div class="review-row"><span>الضريبة</span><span>${money(q.tax)} ج.م</span></div>` : ''}<div class="review-row total"><span>الإجمالي</span><span>${money(q.total)} ج.م</span></div><div class="payment-note">✓ الدفع كاش عند الاستلام</div><div id="submit-error" class="form-error" role="alert"></div><button id="confirm-order" class="button primary wide" data-action="confirm-order">أكد الطلب — ${money(q.total)} ج.م</button><button class="back-edit" data-action="edit-checkout">تعديل الاسم أو الملاحظات</button><button class="back-edit" data-action="edit-location">تغيير مكان التوصيل</button>`;
 $('#checkout-dialog').scrollTop = 0;
}
async function confirmOrder(pending) {
 if (state.submitting) return;
 const payload = pending || state.quote && {token:state.quote.token,idempotency_key:state.quote.key};
 if (!payload) return;
 state.submitting = true; state.pending = payload; writeStorage(pendingKey,payload);
 const button = $('#confirm-order'); if (button) {button.disabled = true; button.textContent = 'جارٍ تسجيل الطلب…';}
 try {
  const result = await api('checkout',payload);
  state.cart = []; state.quote = null; state.pending = null; writeStorage(pendingKey,null); updateCartBadge();
  $('#review-panel').hidden = false; $('#checkout-form').hidden = true; $('#checkout-title').textContent = 'طلبك وصل للفرع.';
  $('#review-panel').innerHTML = `<div class="success"><span class="success-icon" aria-hidden="true">✓</span><h2>اللمة في الطريق.</h2><p>تم تسجيل طلبك، والمتجر هيراجعه ويبدأ تجهيزه.</p><b class="order-number">${esc(result.order.number)}</b><p>الإجمالي: <strong>${money(result.order.total)} ج.م</strong><br>الدفع كاش عند الاستلام.</p><button class="button primary wide" data-close>تمام، بالهنا!</button></div>`;
 } catch (error) {
  if ([403,409,422].includes(error.status)) { state.pending=null; writeStorage(pendingKey,null); }
  const target = $('#submit-error'); if (target) target.textContent = error.message + (state.pending ? ' اضغط مرة تانية للتأكد من نفس الطلب من غير تكرار.' : ' ارجع لتعديل البيانات ومراجعة الإجمالي.');
  if (button) {button.disabled = !state.pending; button.textContent = 'حاول تأكيد نفس الطلب مرة تانية';}
 } finally {
  state.submitting=false;renderSavedAddresses();
  if(!state.pending&&!state.context&&customerProfile){restoreAddress(selectedAddress||customerProfile.selected_id);discoverDelivery(true);}
 }
}
function resumeCheckout() {
 if (!state.pending) return;
 closeDialog($('#cart-dialog')); $('#checkout-form').hidden=true; $('#review-panel').hidden=false; $('#checkout-title').textContent='نتأكد من طلبك.';
 $('#review-panel').innerHTML='<p style="margin-bottom:22px">هنراجع نفس محاولة التأكيد السابقة. الطلب المسجل بالفعل هيرجع تأكيده من غير إنشاء طلب جديد.</p><div id="submit-error" class="form-error" role="alert"></div><button id="confirm-order" class="button primary wide" data-action="confirm-pending">تأكد من تسجيل الطلب</button><button class="back-edit" data-action="edit-checkout">الرجوع للبيانات</button>';
 showDialog('#checkout-dialog');
}
$('#checkout-form').addEventListener('submit',async event => {
 event.preventDefault(); const form=event.currentTarget; const button=$('#review-order'); $('#checkout-error').textContent='';
 if(!state.context){$('#checkout-error').textContent='حدد بيانات وموقع التوصيل الأول.';return;}
 if(!form.reportValidity())return;
 if (!state.cart.length || !state.open) return;
 button.disabled=true; button.textContent='بنحسب الإجمالي والتوصيل…';
 const fields=Object.fromEntries(new FormData(form)),context=state.context,branchId=state.branch,items=cartItems(),attempt=++quoteRequest;
 const current=()=>attempt===quoteRequest&&state.context===context&&state.branch===branchId&&JSON.stringify(cartItems())===JSON.stringify(items)&&$('#checkout-dialog').open&&JSON.stringify(Object.fromEntries(new FormData(form)))===JSON.stringify(fields);
 const payload={...fields,latitude:context.latitude,longitude:context.longitude,location_confirmed:true,branch_id:Number(branchId),items};
 try { const result=await api('quote',payload); if(!current())return; state.quote={...result,key:uuid()}; saveCustomer(context,fields.customer_name); $('#delivery-form').elements.customer_name.value=fields.customer_name.trim(); renderReview(result,fields); }
 catch(error) {if(current())$('#checkout-error').textContent=error.message;}
 finally {if(attempt===quoteRequest){button.disabled=false;button.textContent='مراجعة الإجمالي والتوصيل ←';}}
});
$('#delivery-form').elements.area.addEventListener('input',event => {
 clearTimeout(suggestionTimer); suggestionRequest?.abort(); const query=event.target.value.trim(); $('#address-suggestions').innerHTML='';
 if (query.length<3) return;
 suggestionTimer=setTimeout(async()=>{
  suggestionRequest=new AbortController();
  try { const result=await api('addresses',{query},suggestionRequest.signal); if (event.target.value.trim()!==query) return; $('#address-suggestions').innerHTML=result.items.map((p,i)=>`<button type="button" data-suggestion="${i}">${esc(p.label)}</button>`).join(''); $('#address-suggestions').querySelectorAll('button').forEach(button=>button.addEventListener('click',()=>{const p=result.items[Number(button.dataset.suggestion)];event.target.value=p.label.slice(0,150);setPoint(p.latitude,p.longitude,true);$('#address-suggestions').innerHTML='';})); }
  catch(error) {if(error.name!=='AbortError') $('#location-help').textContent='البحث مش متاح للحظات. حدد مكانك على الخريطة أو استخدم موقعي.';}
 },600);
});
$('#geolocate').addEventListener('click',locateCustomer);
$('#saved-address-select').addEventListener('change',event=>changeAddress(event.target.value));
$('#forget-customer').addEventListener('click',()=>{
 if(state.context||state.discovering||state.submitting||state.pending)return;
 if(!writeCustomer(null)){notify('تعذر مسح البيانات المحفوظة من إعدادات المتصفح.');return;}
 customerProfile=null;selectedAddress='';locationRevision++;state.point=null;
 $('#delivery-form').reset();$('#checkout-form').elements.customer_name.value='';
 clearTimeout(suggestionTimer);suggestionRequest?.abort();$('#address-suggestions').replaceChildren();
 renderSavedAddresses();initMap();locateCustomer();
});
$('#branch-select').addEventListener('change',event=>chooseBranch(event.target.value));
$('#search').addEventListener('input',event=>{state.search=event.target.value.trim();state.limit=12;renderMenu();});
$('#show-more').addEventListener('click',()=>{state.limit+=12;renderMenu();});
$('#store-search')?.addEventListener('input',event=>{state.storeSearch=event.target.value.trim();state.storeLimit=12;renderStoreCards();});
$('#show-more-stores')?.addEventListener('click',()=>{state.storeLimit+=12;renderStoreCards();});
$('#confirm-branch').addEventListener('click',()=>{closeDialog($('#confirm-branch-dialog'));loadBranch(pendingBranch);});
document.addEventListener('click',event=>{
 const close=event.target.closest('[data-close]'); if(close) return closeDialog(close.closest('dialog'));
 const button=event.target.closest('[data-action]');if(!button)return;
 const {action,id,index,delta}=button.dataset;
 if(action==='terms'||action==='policy'){$('#legal-title').textContent=action==='terms'?'الشروط والأحكام':'سياسة الخصوصية';$('#legal-content').textContent=data.info?.[action]||'';showDialog('#legal-dialog');}
 if(action==='choose-branch')focusDelivery();
 if(action==='edit-location')editLocation();
 if(action==='cart')openCart();
 if(action==='branch'){chooseBranch(id);document.getElementById('menu').scrollIntoView({behavior:'smooth'});}
 if(action==='store-filter'){state.storeFilter=button.dataset.filter;state.storeLimit=12;renderStoreCards();}
 if(action==='category'){state.category=id;state.limit=12;renderMenu();}
 if(action==='product')productDialog(id);
 if(action==='retry-menu')loadBranch(state.branch);
 if(action==='retry-address')discoverDelivery(true);
 if(action==='back-menu'){closeDialog($('#cart-dialog'));document.getElementById('menu').scrollIntoView({behavior:'smooth'});}
 if(action==='quantity'){const line=state.cart[Number(index)];if(!line)return;line.quantity=quantity(line.quantity+Number(delta)*(line.mode==='weight'?.25:1));if(line.quantity<=0)state.cart.splice(Number(index),1);else line.quantity=Math.min(99,line.quantity);state.quote=null;renderCart();updateCartBadge();}
 if(action==='remove'){state.cart.splice(Number(index),1);state.quote=null;renderCart();updateCartBadge();}
 if(action==='checkout')openCheckout();
 if(action==='confirm-order')confirmOrder();
 if(action==='confirm-pending')confirmOrder(state.pending);
 if(action==='resume-checkout')resumeCheckout();
 if(action==='edit-checkout'){
  if(state.submitting)return;
  if(state.pending){const target=$('#submit-error');if(target)target.textContent='لازم نتأكد من نتيجة الطلب السابق الأول، علشان مايتسجلش مرتين.';return;}
  state.quote=null;$('#checkout-form').hidden=false;$('#review-panel').hidden=true;$('#checkout-title').textContent='بيانات التوصيل.';
 }
});
document.querySelectorAll('dialog').forEach(dialog=>{
 dialog.addEventListener('cancel',event=>{if(state.submitting)event.preventDefault();});
 dialog.addEventListener('close',()=>{if(!document.querySelector('dialog[open]'))document.body.classList.remove('dialog-open');});
 dialog.addEventListener('click',event=>{if(event.target!==dialog||state.submitting)return;const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)closeDialog(dialog);});
});
document.addEventListener('error',event=>{if(event.target.tagName==='IMG'&&!event.target.dataset.fallback){event.target.dataset.fallback='1';event.target.src=placeholder;event.target.style.objectFit='contain';}},true);
async function discoverDelivery(automatic=false) {
 if(state.discovering||state.submitting)return;
 if(state.pending){$('#delivery-error').textContent='نتأكد من الطلب السابق الأول من سلة الطلب، وبعدها نغيّر بيانات التوصيل.';return;}
 const form=$('#delivery-form');$('#delivery-error').textContent='';
 form.elements.customer_name.value=form.elements.customer_name.value.trim();
 if(!form.reportValidity())return;
 if(!state.point){$('#delivery-error').textContent='حدد مكان التوصيل على الخريطة أو استخدم موقعي الحالي.';return;}
 const payload={...Object.fromEntries(new FormData(form)),...state.point,location_confirmed:form.elements.location_confirmed.checked};
 const button=$('#find-stores');state.discovering=true;button.disabled=true;button.textContent='بندور على المتاجر اللي بتخدمك…';form.inert=true;
 renderSavedAddresses();$('#retry-saved-address').hidden=true;
 if(automatic){showSavedSummary();$('#saved-address-status').textContent='بنحمّل المتاجر اللي بتوصّل للعنوان المحفوظ…';status('بنحمّل متاجر عنوانك…','عنوانك محفوظ، مش محتاج تأكيد البيانات تاني.');}
 try {
  const result=await api('branches',payload);
  if(!Array.isArray(result.branches)||!result.context)throw new Error('تعذر قراءة المتاجر. حاول تاني.');
  state.context=result.context;data.branches=result.branches;state.point={latitude:result.context.latitude,longitude:result.context.longitude};
  saveCustomer(result.context,form.elements.customer_name.value);
  $('#delivery-address').textContent=`${result.context.address}، ${result.context.area}`;$('#delivery-phone').textContent=result.context.customer_phone;
  form.hidden=true;$('#delivery-summary').hidden=false;renderBranches();
  $('#saved-address-status').textContent=data.branches.length?'تقدر تختار متجرك وتبدأ طلبك مباشرة.':'مفيش متجر بيغطي العنوان ده حاليًا. اختار عنوان تاني من القائمة بالأعلى.';
  status(data.branches.length?'اختار المتجر المناسب ليك':'مفيش متجر بيغطي موقعك حاليًا',data.branches.length?'المتاجر مرتبة من الأقرب. اختار متجر لعرض المنيو والأسعار.':'جرّب عنوان توصيل تاني أو غيّر الموقع من بيانات التوصيل.');
  if(!automatic)document.getElementById(data.branches.length?'branches':'delivery').scrollIntoView({behavior:'smooth'});
 }catch(error){state.context=null;data.branches=[];renderBranches();$('#delivery-error').textContent=error.message;
  if(automatic){$('#saved-address-status').textContent=error.message;$('#retry-saved-address').hidden=false;status('تعذر تحميل متاجر العنوان','العنوان محفوظ. حاول تحميل المتاجر تاني من عنوان التوصيل.');}
 }
 finally{state.discovering=false;form.inert=false;button.disabled=false;button.textContent='اعرض المتاجر اللي بتخدمني ←';renderSavedAddresses();}
}
$('#delivery-form').addEventListener('submit',event=>{event.preventDefault();discoverDelivery();});
$('#confirm-location-change').addEventListener('click',()=>{if(state.pending||state.submitting)return;const id=pendingAddress;pendingAddress=null;if(id!==null){applyAddress(id);return;}document.querySelectorAll('dialog[open]').forEach(closeDialog);resetDelivery();});
renderBranches();updateCartBadge();initMap();
if(customerProfile){restoreAddress(customerProfile.selected_id);showSavedSummary();if(!state.pending)discoverDelivery(true);else $('#saved-address-status').textContent='نتأكد من نتيجة الطلب السابق من السلة، وبعدها تظهر متاجر عنوانك تلقائيًا.';}
else{renderSavedAddresses();locateCustomer();}
})();
