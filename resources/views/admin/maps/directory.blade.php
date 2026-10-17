@include('admin.maps.assets')
<script>
(function(){
var container=document.getElementById('map');if(!container||!window.L)return;
var items=@json($arr),kind=@json($mapKind),map=L.map(container).setView([30.0444,31.2357],6),bounds=[],notice=document.createElement('p');notice.setAttribute('role','status');container.before(notice);
L.tileLayer(@json(config('services.maps.tile_url')),{maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',referrerPolicy:'strict-origin-when-cross-origin'}).addTo(map).on('tileerror',function(){notice.textContent='تعذر تحميل بعض أجزاء الخريطة. تحقق من اتصال الإنترنت.';});
items.forEach(function(item){var lat=Number(item[1]),lng=Number(item[2]);if(item[1]==null||item[2]==null||!Number.isFinite(lat)||!Number.isFinite(lng)||Math.abs(lat)>90||Math.abs(lng)>180||(lat===0&&lng===0))return;
var content=document.createElement('div'),link=document.createElement('a');link.textContent=item[0]||'';try{var target=new URL(item[3],location.href);if(target.origin===location.origin){link.href=target.href;link.target='_blank';link.rel='noopener';}}catch(e){}content.appendChild(link);var description=document.createElement('p');description.textContent=item[kind==='restaurant'?5:4]||'';content.appendChild(description);
L.circleMarker([lat,lng],{radius:8,color:'#fff',weight:2,fillColor:'#ed7819',fillOpacity:1}).addTo(map).bindPopup(content);bounds.push([lat,lng]);});
if(bounds.length)map.fitBounds(bounds,{padding:[30,30],maxZoom:15});else notice.textContent='لا توجد مواقع صالحة للعرض.';
if(window.DashboardSPA)DashboardSPA.onCleanup(function(){map.remove();notice.remove();});
}());
</script>
