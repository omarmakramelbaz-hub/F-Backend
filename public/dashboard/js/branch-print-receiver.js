(function () {
    'use strict';
    if (window.DashboardBranchPrinter) return;
    var node = document.getElementById('branch-print-bootstrap');
    if (!node) return;
    var boot = JSON.parse(node.textContent), stopped = false, timer, latest = null;
    var state = { status: boot.labels.ready, latest: null, soundReady: false };
    var context = null, pendingSound = false, pendingIds = [], heardIds = new Set(), bannerTimer;
    var soundKey = 'branch-delivery-sound:' + boot.actor_id + ':' + boot.branch;
    try { heardIds = new Set(JSON.parse(sessionStorage.getItem(soundKey) || '[]')); } catch (_) {}
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
    function report(status) { state.status = status; document.dispatchEvent(new CustomEvent('dashboard:branch-print', { detail: { status: status, latest: state.latest, soundReady: state.soundReady } })); }
    function soundControl() {
        var button = document.createElement('button'); button.type = 'button'; button.dataset.branchSoundEnable = '';
        button.textContent = boot.labels.enable_sound; button.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:1080;padding:10px 16px;background:#f57800;color:white;border:0;border-radius:10px;font-weight:bold;cursor:pointer';
        button.addEventListener('click', unlockSound); document.body.appendChild(button); return button;
    }
    var soundButton = soundControl();
    function playPendingSound() {
        if (stopped || !pendingSound || !context || context.state !== 'running') return;
        try {
            // Keep one context; a context created by a polling callback is blocked by autoplay.
            [660,880,1100].forEach(function (frequency, index) {
                var tone = context.createOscillator(), gain = context.createGain(), start = context.currentTime + index * 0.24;
                tone.type = 'sine'; tone.frequency.value = frequency;
                gain.gain.setValueAtTime(0,start); gain.gain.linearRampToValueAtTime(0.18,start + 0.025);
                gain.gain.exponentialRampToValueAtTime(0.001,start + 0.22);
                tone.connect(gain); gain.connect(context.destination); tone.start(start); tone.stop(start + 0.23);
                tone.onended = function () { tone.disconnect(); gain.disconnect(); };
            });
            pendingSound = false; pendingIds.forEach(function (id) { heardIds.add(id); });
            try { sessionStorage.setItem(soundKey,JSON.stringify(Array.from(heardIds).slice(-200))); } catch (_) {}
            pendingIds = [];
        } catch (_) { state.soundReady = false; soundButton.hidden = false; }
    }
    function unlockSound() {
        if (stopped) return;
        try {
            var Audio = window.AudioContext || window.webkitAudioContext; if (!Audio) return;
            if (!context || context.state === 'closed') context = new Audio();
            Promise.resolve(context.resume()).then(function () {
                if (stopped) return;
                state.soundReady = context.state === 'running'; soundButton.hidden = state.soundReady;
                playPendingSound();
            }).catch(function () { state.soundReady = false; soundButton.hidden = false; });
        } catch (_) { state.soundReady = false; soundButton.hidden = false; }
    }
    document.addEventListener('pointerdown', unlockSound);
    document.addEventListener('keydown', unlockSound);
    function alertOrder() {
        var banner = document.querySelector('[data-branch-order-alert]');
        if (!banner) { banner = document.createElement('a'); banner.dataset.branchOrderAlert = ''; banner.href = address(boot.orders) + '?view=orders'; banner.setAttribute('role','status'); banner.style.cssText = 'position:fixed;top:90px;left:20px;z-index:1080;padding:14px 20px;background:#f57800;color:white;border-radius:10px;box-shadow:0 4px 20px #0003;font-weight:bold'; document.body.appendChild(banner); }
        banner.textContent = boot.labels.incoming; banner.hidden = false;
        clearTimeout(bannerTimer); bannerTimer = setTimeout(function () { banner.hidden = true; }, 8000);
        pendingSound = true;
        playPendingSound();
    }
    async function poll() {
        if (stopped) return;
        try {
            var url = new URL(address(boot.jobs)); url.searchParams.set('branch',boot.branch);
            var result = await request(url.href);
            if (stopped) return;
            state.latest = result.latest_ticket_id;
            if (Array.isArray(result.incoming_ticket_ids)) {
                var incoming = result.incoming_ticket_ids.filter(function (id) { return !heardIds.has(id); });
                var changed = incoming.some(function (id) { return pendingIds.indexOf(id) === -1; });
                pendingIds = incoming;
                if (changed) alertOrder();
                // A cancelled or completed order must not sound later when audio is unlocked.
                if (!incoming.length) pendingSound = false;
            } else if ((latest !== null && state.latest > latest) || (latest === null && (result.jobs || []).length)) {
                if (!heardIds.has(state.latest)) { pendingIds = [state.latest]; alertOrder(); }
            }
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
    window.addEventListener('pagehide', function () {
        stopped = true; clearTimeout(timer); clearTimeout(bannerTimer);
        document.removeEventListener('pointerdown',unlockSound); document.removeEventListener('keydown',unlockSound);
        if (context) context.close().catch(function () {});
    });
    timer = setTimeout(poll,500);
}());
