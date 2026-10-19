(function () {
    'use strict';
    if (window.DashboardBranchPrinter) return;
    var node = document.getElementById('branch-print-bootstrap');
    if (!node) return;
    var boot = JSON.parse(node.textContent), stopped = false, timer, latest = null;
    var state = { status: boot.labels.ready, latest: null };
    window.DashboardBranchPrinter = state;
    function address(value) { var url = new URL(value, location.href); if (url.origin !== location.origin) throw new Error('Invalid print origin'); return url.href; }
    async function request(url, values) {
        var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 15000);
        var options = { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
        if (values) {
            options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').content;
            options.body = JSON.stringify(values);
        }
        try { var response = await fetch(address(url), options); var result = await response.json(); if (!response.ok || !result.success) { var error = new Error('Print request failed'); error.status = response.status; throw error; } return result; }
        finally { clearTimeout(timeout); }
    }
    function report(status) { state.status = status; document.dispatchEvent(new CustomEvent('dashboard:branch-print', { detail: { status: status, latest: state.latest } })); }
    function alertOrder() {
        var banner = document.querySelector('[data-branch-order-alert]');
        if (!banner) { banner = document.createElement('a'); banner.dataset.branchOrderAlert = ''; banner.href = address(boot.orders) + '?view=orders'; banner.setAttribute('role','status'); banner.style.cssText = 'position:fixed;top:90px;left:20px;z-index:1080;padding:14px 20px;background:#f57800;color:white;border-radius:10px;box-shadow:0 4px 20px #0003;font-weight:bold'; document.body.appendChild(banner); }
        banner.textContent = boot.labels.incoming; banner.hidden = false;
        setTimeout(function () { banner.hidden = true; }, 8000);
        try { var Audio = window.AudioContext || window.webkitAudioContext; if (!Audio) return; var context = new Audio(), tone = context.createOscillator(), gain = context.createGain(); tone.frequency.value = 880; gain.gain.value = 0.12; tone.connect(gain); gain.connect(context.destination); tone.start(); tone.stop(context.currentTime + 0.35); tone.onended = function () { context.close(); }; } catch (_) {}
    }
    async function poll() {
        if (stopped) return;
        try {
            var url = new URL(address(boot.jobs)); url.searchParams.set('branch',boot.branch);
            var result = await request(url.href);
            if (stopped) return;
            state.latest = result.latest_ticket_id;
            if (latest !== null && state.latest > latest) alertOrder();
            latest = state.latest;
            report(result.attention ? boot.labels.attention : boot.labels.ready);
            for (var job of result.jobs || []) {
                if (stopped) break;
                var values = { branch: boot.branch, job_id: job.id, claim_token: crypto.randomUUID() }, claim;
                try { claim = await request(boot.claim,values); }
                catch (error) { if (error.status === 409) continue; throw error; }
                if (stopped) break;
                var printed = 'failed';
                try { if (!window.DashboardPrint) throw new Error('Printer unavailable'); await window.DashboardPrint.print(address(claim.print_url)); printed = 'invoked'; }
                catch (_) { report(boot.labels.attention); }
                if (stopped) break;
                await request(boot.complete,Object.assign({},values,{result:printed}));
            }
        } catch (_) { if (!stopped) report(boot.labels.offline); }
        finally { if (!stopped) timer = setTimeout(poll,2500); }
    }
    window.addEventListener('pagehide', function () { stopped = true; clearTimeout(timer); });
    timer = setTimeout(poll,500);
}());
