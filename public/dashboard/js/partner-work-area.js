/* Fixed partner coverage. Google Circle radius is in metres, form/storage in km. */
(function (root) {
    'use strict';
    function normalize(value) {
        return String(value == null ? '' : value).trim()
            .replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 1632); })
            .replace(/[۰-۹]/g, function (c) { return String(c.charCodeAt(0) - 1776); })
            .replace(/٫/g, '.');
    }
    function radius(value) {
        var s = normalize(value), n = Number(s);
        return /^[0-9]{1,3}$/.test(s) && n >= 1 && n <= 255 ? n : null;
    }
    function point(lat, lng) {
        var a = normalize(lat), b = normalize(lng), x = Number(a), y = Number(b);
        if (!a || !b || !isFinite(x) || !isFinite(y) || Math.abs(x) > 90 || Math.abs(y) > 180) return null;
        return {lat: Number(x.toFixed(7)), lng: Number(y.toFixed(7))};
    }
    function init(el, maps) {
        if (el.__partnerWorkArea) return el.__partnerWorkArea;
        var doc = el.ownerDocument;
        var lat = doc.getElementById('partner-work-lat'), lng = doc.getElementById('partner-work-lng');
        var input = doc.getElementById('partner-work-radius'), summary = doc.getElementById('partner-work-summary');
        var validation = doc.getElementById('partner-work-validation'), canvas = doc.getElementById('partner-work-map');
        var selected = point(lat.value, lng.value), marker = null, circle = null;
        var map = new maps.Map(canvas, {center: selected || {lat: 30.0444, lng: 31.2357}, zoom: selected ? 12 : 6,
            streetViewControl: false, mapTypeControl: false, fullscreenControl: false, gestureHandling: 'cooperative'});
        function render(fit) {
            var km = radius(input.value);
            if (selected) {
                if (!marker) {
                    marker = new maps.Marker({map: map, position: selected, draggable: true, title: 'مركز منطقة عمل الشريك'});
                    marker.addListener('drag', function (e) { select(e.latLng, false); });
                    marker.addListener('dragend', function (e) { select(e.latLng, false); });
                } else marker.setPosition(selected);
            }
            if (selected && km !== null) {
                if (!circle) circle = new maps.Circle({map: map, center: selected, radius: km * 1000,
                    strokeColor: '#FD7201', strokeWeight: 2, strokeOpacity: 0.9, fillColor: '#FD7201', fillOpacity: 0.16, clickable: false});
                circle.setCenter(selected);
                circle.setRadius(km * 1000);
                if (fit && circle.getBounds()) map.fitBounds(circle.getBounds(), 24);
                summary.textContent = 'نطاق العمل: ' + km + ' كم في كل اتجاه من الدبوس.';
            } else {
                if (circle) { circle.setMap(null); circle = null; }
                summary.textContent = selected ? 'أدخل نطاق العمل بالكيلومتر لإظهار الدائرة.' : 'لم يتم تحديد دبوس منطقة العمل بعد.';
            }
            input.setCustomValidity(km === null ? 'أدخل عددًا صحيحًا من 1 إلى 255 كم.' : '');
        }
        function select(position, fit) {
            var candidate = point(typeof position.lat === 'function' ? position.lat() : position.lat,
                typeof position.lng === 'function' ? position.lng() : position.lng);
            if (!candidate) return;
            selected = candidate;
            lat.value = selected.lat.toFixed(7);
            lng.value = selected.lng.toFixed(7);
            validation.hidden = true;
            render(fit);
        }
        map.addListener('click', function (e) { select(e.latLng, false); });
        input.addEventListener('input', function () { input.value = normalize(input.value); render(true); });
        var form = el.closest('form');
        if (form) form.addEventListener('submit', function (event) {
            input.value = normalize(input.value);
            if (!selected || radius(input.value) === null) {
                event.preventDefault();
                validation.textContent = !selected ? 'حدد موقع الشريك بدبوس على الخريطة قبل الحفظ.' : 'أدخل نطاقًا صحيحًا من 1 إلى 255 كم.';
                validation.hidden = false;
                (!selected ? canvas : input).focus();
            }
        });
        if (selected) select(selected, true); else render(false);
        el.__partnerWorkArea = {map: map};
        return el.__partnerWorkArea;
    }
    function boot(doc) {
        var el = doc.getElementById('partner-work-area');
        if (!el) return;
        var error = doc.getElementById('partner-work-map-error'), ready = false, failed = false;
        var form = el.closest('form');
        if (form) form.addEventListener('submit', function (event) {
            if (!ready || failed) {
                event.preventDefault();
                error.hidden = false;
                error.textContent = 'الخريطة غير جاهزة. أعد تحميل الصفحة وتحقق من اتصال الإنترنت وإعدادات الخرائط قبل حفظ منطقة العمل.';
                error.scrollIntoView({block: 'nearest'});
            }
        });
        function fail() {
            if (ready && !failed) return;
            failed = true;
            error.hidden = false;
            error.textContent = 'تعذر تحميل الخريطة. تحقق من الإنترنت وتفعيل مفتاح Google Maps للداش بورد، ثم أعد تحميل الصفحة. بياناتك لم تتغير.';
        }
        function start() {
            if (ready || failed) return;
            try {
                if (!root.google || !root.google.maps || !root.google.maps.Map) { fail(); return; }
                init(el, root.google.maps);
                ready = true;
                error.hidden = true;
            } catch (e) { fail(); }
        }
        var oldAuthFailure = root.gm_authFailure;
        root.gm_authFailure = function () {
            ready = false; failed = false; fail();
            if (typeof oldAuthFailure === 'function') oldAuthFailure();
        };
        if (root.google && root.google.maps && root.google.maps.Map) { start(); return; }
        var key = el.getAttribute('data-maps-key');
        if (!key) { fail(); return; }
        // Do not load a second copy when a layout has already requested Maps.
        var existing = doc.querySelector('script[src*="maps.googleapis.com/maps/api/js"]');
        if (existing) {
            var attempts = 0;
            var timer = root.setInterval(function () {
                if (root.google && root.google.maps && root.google.maps.Map) { root.clearInterval(timer); start(); }
                else if (++attempts >= 80) { root.clearInterval(timer); fail(); }
            }, 250);
            existing.addEventListener('error', fail);
            return;
        }
        root.initPartnerWorkAreaMap = start;
        var script = doc.createElement('script');
        script.async = true;
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&language=ar&loading=async&callback=initPartnerWorkAreaMap';
        script.addEventListener('error', fail);
        doc.head.appendChild(script);
        root.setTimeout(function () { if (!ready) fail(); }, 20000);
    }
    var api = {normalize: normalize, radius: radius, point: point, init: init, boot: boot};
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root.document) {
        if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', function () { boot(root.document); });
        else boot(root.document);
    }
}(typeof window !== 'undefined' ? window : globalThis));
