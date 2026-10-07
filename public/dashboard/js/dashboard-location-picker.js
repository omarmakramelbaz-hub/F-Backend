(function () {
'use strict';
if (window.DashboardLocationPicker) return;
var googleLoad, failed = false, authInstalled = false, rejectGoogle, pickers = new Set();
function installAuthHandler() {
    if (authInstalled) return;
    authInstalled = true;
    var previous = window.gm_authFailure;
    window.gm_authFailure = function () {
        failed = true;
        if (rejectGoogle) rejectGoogle(Error('Google authorization failed'));
        pickers.forEach(function (p) { p.fallback(); });
        if (previous) previous();
    };
}
function sdk(key) {
    // Install even when another dashboard page already loaded the Google SDK.
    installAuthHandler();
    if (failed) return Promise.reject(Error('Google authorization failed'));
    if (window.google && google.maps && google.maps.Geocoder) return Promise.resolve();
    if (!key) return Promise.reject(Error('Missing map key'));
    if (!googleLoad) googleLoad = new Promise(function (resolve, reject) {
        var timer = setTimeout(function () { reject(Error('Map timeout')); }, 12000);
        rejectGoogle = function (error) { clearTimeout(timer); reject(error); };
        window.dashboardLocationReady = function () { clearTimeout(timer); resolve(); };
        var script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&language=ar&callback=dashboardLocationReady';
        script.async = true;
        script.onerror = function () { rejectGoogle(Error('Map unavailable')); };
        document.head.appendChild(script);
    });
    return googleLoad;
}
function valid(lat, lng) {
    return lat !== null && lng !== null && lat !== '' && lng !== '' && Number.isFinite(Number(lat)) && Number.isFinite(Number(lng)) && Math.abs(Number(lat)) <= 90 && Math.abs(Number(lng)) <= 180;
}
function addressArea(components) {
    var priorities=['sublocality_level_1','sublocality','neighborhood','administrative_area_level_3','locality','administrative_area_level_2'];
    for (var type of priorities) {
        var component=(components||[]).find(function(c){return Array.isArray(c.types)&&c.types.includes(type)&&typeof c.longText==='string'&&c.longText.trim();});
        if(component)return component.longText.trim().slice(0,150);
    }
    return '';
}
function create(container, options) {
    var routePath = null, placesToken, googlePoint=false, predictions = new Map();
    var map, marker, circle, origin, routeLine, provider, observer, disposed = false, g = 0, radius = options.radius || 0;
    var point = valid(options.latitude, options.longitude) ? [Number(options.latitude), Number(options.longitude)] : null;
    var center = point || options.center || [30.0444, 31.2357];
    var branch = options.origin && valid(options.origin[0], options.origin[1]) ? options.origin.map(Number) : null;
    var notice = document.createElement('p');
    notice.className = 'dashboard-map-notice';
    notice.setAttribute('role', 'status');
    container.before(notice);
    container.style.minHeight = '260px';
    container.style.direction = 'ltr';
    function line() {
        if (!map || !branch) return;
        var path = point ? (routePath || (options.routeOnly ? [] : [branch, point])) : [];
        if (provider === 'google') {
            if (!routeLine) routeLine = new google.maps.Polyline({map: map, strokeColor: '#f58220', strokeOpacity: .9, strokeWeight: 4, clickable: false});
            routeLine.setPath(path.map(function (p) { return {lat: p[0], lng: p[1]}; }));
        } else {
            if (!routeLine) routeLine = L.polyline([], {color: '#f58220', weight: 4, opacity: .9, interactive: false}).addTo(map);
            routeLine.setLatLngs(path);
        }
    }
    function frame() {
        if (!map || !point) return;
        if (provider === 'google') {
            if (routePath) { var routeBounds=new google.maps.LatLngBounds(); routePath.forEach(function(p){routeBounds.extend({lat:p[0],lng:p[1]});}); map.fitBounds(routeBounds,45); }
            else if (branch) { var bounds = new google.maps.LatLngBounds(); bounds.extend({lat: branch[0], lng: branch[1]}); bounds.extend({lat: point[0], lng: point[1]}); map.fitBounds(bounds, 45); }
            else { map.setCenter({lat: point[0], lng: point[1]}); map.setZoom(15); }
        } else if (routePath) map.fitBounds(routePath.concat([branch,point].filter(Boolean)),{padding:[30,30],maxZoom:16});
        else if (branch) map.fitBounds([branch, point], {padding: [35, 35], maxZoom: 16});
        else map.setView(point, 15);
    }
    function set(p, pan) {
        if (!valid(p[0], p[1])) return;
        routePath = null;
        point = [Number(p[0]), Number(p[1])];
        if (!map) return;
        if (provider === 'google') { marker.setPosition({lat: point[0], lng: point[1]}); marker.setVisible(true); if (circle) circle.setCenter(marker.getPosition()); }
        else { marker.setLatLng(point).setOpacity(1); if (circle) circle.setLatLng(point); }
        line();
        if (pan !== false) frame();
    }
    function changed(lat, lng, pan) {
        if (disposed) return;
        if (options.locked && options.locked()) { if (point) set(point, false); return; }
        googlePoint=false;
        g++; // A manual choice supersedes any address search still in flight.
        set([lat, lng], pan);
        if (options.change) options.change(lat, lng);
    }
    function clear() {
        g++; point = null; routePath = null; googlePoint=false; predictions.clear();
        if (marker) { if (provider === 'google') marker.setVisible(false); else marker.setOpacity(0); }
        line();
        notice.textContent = 'اكتب عنوان العميل لتحديد دبوسه. العلامة الزرقاء تخص الفرع.';
    }
    function removeGoogle() {
        if (observer) { observer.disconnect(); observer = null; }
        if (!map || provider !== 'google') return;
        google.maps.event.clearInstanceListeners(map);
        if (marker) { google.maps.event.clearInstanceListeners(marker); marker.setMap(null); }
        if (circle) circle.setMap(null);
        if (origin) origin.setMap(null);
        if (routeLine) routeLine.setMap(null);
        map = marker = circle = origin = routeLine = null;
    }
    function fallback() {
        if (disposed || provider === 'osm') return;
        g++; predictions.clear(); placesToken=null;
        if(googlePoint){point=null;routePath=null;googlePoint=false;if(options.unavailable)options.unavailable();}
        removeGoogle();
        provider = 'osm';
        container.replaceChildren();
        notice.textContent = options.preferOpenMap ? 'اضغط على الخريطة أو حرّك الدبوس لتحديد الموقع.' : 'تعذر تشغيل Google. الخريطة البديلة متاحة لاختيار دبوس العميل؛ التحديد من العنوان يحتاج تفعيل خدمة Google في إعدادات المطعم.';
        if (!window.L) { notice.textContent = 'تعذر تحميل الخريطة. أدخل الإحداثيات وأكد الموقع.'; return; }
        map = L.map(container, {scrollWheelZoom: false}).setView(point || center, point ? 15 : 12);
        L.tileLayer(options.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors', referrerPolicy: 'strict-origin-when-cross-origin'}).addTo(map).on('tileerror', function () { if (!notice.textContent.includes('أجزاء الخريطة')) notice.textContent += ' تعذر تحميل بعض أجزاء الخريطة. تحقق من الإنترنت.'; });
        marker = L.marker(point || center, {draggable: true, opacity: point ? 1 : 0, icon: L.divIcon({className: 'dashboard-map-pin', html: '<span style="display:block;background:#f58220;border:3px solid white;border-radius:50%;width:24px;height:24px;box-shadow:0 2px 8px #0006"></span>', iconSize: [24,24], iconAnchor: [12,12]})}).addTo(map);
        if (branch) marker.bindTooltip('العميل');
        marker.on('dragend', function () { var p = marker.getLatLng(); changed(p.lat, p.lng, false); });
        map.on('click', function (e) { changed(e.latlng.lat, e.latlng.lng, false); });
        circle = L.circle(point || center, {radius: radius, color: '#f58220', weight: 1}).addTo(map);
        if (branch) L.circleMarker(branch, {radius: 7, color: '#173e70', fillOpacity: 1}).addTo(map).bindTooltip('الفرع', {permanent: true});
        line(); if (point) frame();
    }
    var ready=(options.preferOpenMap ? Promise.reject() : sdk(options.key)).then(function () {
        if (disposed || failed) return fallback();
        provider = 'google';
        var c = {lat: (point || center)[0], lng: (point || center)[1]};
        map = new google.maps.Map(container, {center: c, zoom: point ? 15 : 12, streetViewControl: false, mapTypeControl: false});
        marker = new google.maps.Marker({map: map, position: c, visible: !!point, draggable: true, title: branch ? 'موقع العميل' : 'الموقع', label: branch ? 'العميل' : undefined});
        circle = new google.maps.Circle({map: map, center: c, radius: radius, strokeColor: '#f58220', fillColor: '#f58220', fillOpacity: .12, strokeWeight: 1});
        map.addListener('click', function (e) { changed(e.latLng.lat(), e.latLng.lng(), false); });
        marker.addListener('dragend', function (e) { changed(e.latLng.lat(), e.latLng.lng(), false); });
        if (branch) origin = new google.maps.Marker({map: map, position: {lat: branch[0], lng: branch[1]}, label: 'الفرع', title: 'موقع الفرع'});
        line(); if (point) frame();
        notice.textContent = options.routeOnly ? 'اختر عنوان العميل ثم راجع الدبوس وأكده. يظهر الطريق بعد حساب الخدمة.' : 'حدد دبوس العميل بدقة ثم أكد الموقع. الخط يوضح المسافة المباشرة من الفرع.';
        // Billing failures may render a watermarked map without invoking gm_authFailure.
        observer = new MutationObserver(function () {
            if (container.querySelector('.gm-err-container,.gm-err-message') || /for development purposes only/i.test(container.textContent)) { failed = true; fallback(); }
        });
        observer.observe(container, {childList: true, subtree: true, characterData: true});
        if (container.querySelector('.gm-err-container,.gm-err-message') || /for development purposes only/i.test(container.textContent)) { failed = true; fallback(); }
    }).catch(fallback);
    var api = {
        set: function (lat, lng) { set([lat, lng]); }, clear: clear,
        route: function(path){routePath=Array.isArray(path)&&path.length>1&&path.every(function(p){return Array.isArray(p)&&valid(p[0],p[1]);})?path:null;line();if(routePath)frame();},
        suggest: async function(query){
            var requestGeneration=g;
            await ready;
            if(disposed||failed||provider!=='google'||!google.maps.importLibrary)throw Error('بحث Google غير متاح. يلزم مفتاح Google صالح مع Maps JavaScript API وPlaces API (New) والفوترة ونطاق الموقع.');
            var lib=await google.maps.importLibrary('places');
            if(disposed||requestGeneration!==g)return [];
            if(!placesToken)placesToken=new lib.AutocompleteSessionToken();
            var request={input:query,includedRegionCodes:['eg'],language:'ar',region:'eg',sessionToken:placesToken};
            if(branch)request.locationBias={center:{lat:branch[0],lng:branch[1]},radius:30000};
            var result=await lib.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);
            if(disposed||requestGeneration!==g)return [];predictions.clear();
            return (result.suggestions||[]).filter(function(s){return !!s.placePrediction;}).map(function(s){var p=s.placePrediction;predictions.set(p.placeId,p);return{id:p.placeId,label:p.text.toString()};});
        },
        resolve: async function(item){
            if(disposed||failed||provider!=='google'||!predictions.has(item.id))throw Error('تعذر تحديد العنوان. أعد البحث ثم اختره.');
            var generation=g,place=predictions.get(item.id).toPlace();placesToken=null;predictions.clear();await place.fetchFields({fields:['location','formattedAddress','addressComponents']});
            if(disposed||failed||provider!=='google'||generation!==g)throw Error('تغير العنوان أو الفرع. أعد اختيار العنوان.');
            if(!place.location)throw Error('لا توجد إحداثيات لهذا العنوان.');
            googlePoint=true;
            return {label:place.formattedAddress||item.label,area:addressArea(place.addressComponents),latitude:place.location.lat(),longitude:place.location.lng()};
        },
        invalidate: function () { g++; },
        resize: function () { if (provider === 'osm' && map) map.invalidateSize(); else if (provider === 'google' && map) google.maps.event.trigger(map, 'resize'); },
        radius: function (value) { radius = value; if (circle) circle.setRadius(value); },
        fallback: fallback,
        geocode: async function (address) {
            var generation = ++g;
            await sdk(options.key).catch(function () {});
            if (disposed || generation !== g) return null;
            if (provider !== 'google' || failed) { notice.textContent = 'خدمة تحديد العنوان غير متاحة من Google حاليًا. اختر دبوس العميل على الخريطة، أو فعّل الخدمة لإتاحة التحديد التلقائي.'; return null; }
            notice.textContent = 'جاري تحديد العنوان…';
            return new Promise(function (resolve) {
                var settled = false, timer = setTimeout(function () { finish(null); if (!disposed && generation === g) notice.textContent = 'تأخر البحث عن العنوان. أعد المحاولة أو حدد الدبوس يدويًا.'; }, 12000);
                function finish(result) { if (settled) return; settled = true; clearTimeout(timer); resolve(result); }
                new google.maps.Geocoder().geocode({address: address, region: 'eg', componentRestrictions: {country: 'EG'}}, function (results, status) {
                    if (settled) return;
                    if (disposed || generation !== g) { finish(null); return; }
                    if (status === 'REQUEST_DENIED') { fallback(); notice.textContent = 'Google رفضت تحديد العنوان. راجع تفعيل Geocoding API وصلاحيات المفتاح والفوترة؛ يمكنك اختيار دبوس العميل يدويًا الآن.'; finish(null); return; }
                    if (status !== 'OK' || !results || !results.length) { notice.textContent = status === 'ZERO_RESULTS' ? 'لم نعثر على العنوان. اكتب الشارع والمنطقة والمدينة أو حدد دبوس العميل يدويًا.' : 'تعذر تحديد العنوان الآن. أعد المحاولة أو حدد دبوس العميل يدويًا.'; finish(null); return; }
                    var result = results[0], p = result.geometry.location;
                    notice.textContent = (result.partial_match || result.geometry.location_type === 'APPROXIMATE' ? 'موقع تقريبي: ' : 'موقع مقترح: ') + result.formatted_address + ' — راجع دبوس العميل وأكده.';
                    changed(p.lat(), p.lng(), true);
                    finish([p.lat(), p.lng()]);
                });
            });
        },
        destroy: function () { disposed = true; g++; pickers.delete(api); if (provider === 'osm' && map) map.remove(); else removeGoogle(); notice.remove(); }
    };
    pickers.add(api);
    return api;
}
window.DashboardLocationPicker = {create: create, valid: valid};
}());
