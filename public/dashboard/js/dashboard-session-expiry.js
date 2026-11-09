(function () {
    'use strict';
    // A page whose session ended (another sign-in, the server's limit, a restart) answers its background requests with
    // a short 401 marked X-GO-Session: expired. The page then opens the sign-in page once (the PC program signs in
    // again by itself there) instead of asking the server every few seconds for nothing.
    if (window.__goSessionExpiry) return;
    window.__goSessionExpiry = true;
    var login = (document.currentScript && document.currentScript.dataset.login) || '/admin/login', leaving = false;
    function expired() {
        if (leaving) return;
        var last = 0; try { last = Number(sessionStorage.getItem('go-session-expired-at') || 0); } catch (_) {}
        if (Date.now() - last < 60000) return; // at most once a minute
        leaving = true;
        try { sessionStorage.setItem('go-session-expired-at', String(Date.now())); } catch (_) {}
        location.assign(login);
    }
    function check(status, header) { if (status === 401 && String(header || '').toLowerCase() === 'expired') expired(); }
    var original = window.fetch;
    if (typeof original === 'function') {
        window.fetch = function () {
            return original.apply(this, arguments).then(function (response) {
                try { check(response.status, response.headers.get('X-GO-Session')); } catch (_) {}
                return response;
            });
        };
    }
    if (window.jQuery) window.jQuery(document).ajaxError(function (event, xhr) { try { check(xhr.status, xhr.getResponseHeader('X-GO-Session')); } catch (_) {} });
})();
