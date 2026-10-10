(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardWhatsAppReplies) { window.DashboardWhatsAppReplies.mount(); return; }
    var active = null;
    function mount() {
        var host = document.getElementById('whatsapp-inbox'), bootstrap = document.getElementById('whatsapp-replies-bootstrap');
        if (!host || !bootstrap || active && active.host === host) return;
        destroy();
        var config;
        try { config = JSON.parse(bootstrap.textContent); } catch (error) { return; }
        var panel = host.querySelector('[data-wa-replies]'); if (!panel) return;
        var labels = config.labels || {}, status = panel.querySelector('[data-wa-reply-status]');
        var form = panel.querySelector('[data-wa-reply-form]'), text = panel.querySelector('[data-wa-reply-text]');
        var send = panel.querySelector('[data-wa-reply-send]'), refresh = panel.querySelector('[data-wa-reply-refresh]');
        var count = panel.querySelector('[data-wa-reply-count]');
        var record = panel.querySelector('[data-wa-voice-record]'), stop = panel.querySelector('[data-wa-voice-stop]');
        var cancel = panel.querySelector('[data-wa-voice-cancel]'), voiceSend = panel.querySelector('[data-wa-voice-send]');
        var preview = panel.querySelector('[data-wa-voice-preview]'), voiceTime = panel.querySelector('[data-wa-voice-time]');
        var voiceNote = panel.querySelector('[data-wa-voice-status]');
        var audit = panel.querySelector('[data-wa-reply-audit]'), auditList = panel.querySelector('[data-wa-reply-audit-list]');
        var controllers = new Map(), conversation = 0, generation = 0, closed = false, denied = false;
        var sending = false, checking = false, state = null, attempt = null, uncertain = false;
        var voiceRun = null, voiceBlob = null, voiceUrl = null, openingMicrophone = false, microphoneRequest = 0;
        function note(value, error) { status.textContent = value || ''; status.classList.toggle('is-error', Boolean(error)); }
        function abort() { controllers.forEach(function (controller) { controller.abort(); }); controllers.clear(); }
        function clear(reason) {
            generation++; abort(); conversation = 0; state = null; attempt = null; uncertain = false; sending = false; checking = false;
            clearVoice(); openingMicrophone = false;
            text.value = ''; count.textContent = '0 / 4096'; panel.hidden = true;
            if (audit) { auditList.textContent = ''; audit.hidden = true; audit.open = false; }
            note(reason === 'denied' ? labels.denied : '', reason === 'denied'); controls();
        }
        function controls() {
            var available = !closed && !denied && conversation && navigator.onLine !== false && !document.hidden;
            var recording = Boolean(voiceRun) || openingMicrophone;
            text.disabled = !available || sending || recording || !state || !state.available;
            text.readOnly = uncertain || Boolean(state && !state.can_reply);
            send.disabled = !available || sending || recording || checking || uncertain || !state || !state.available || !state.can_reply
                || !text.value.trim() || text.value.length > 4096;
            refresh.disabled = !available || checking || sending || recording;
            if (record) {
                record.disabled = !available || sending || checking || uncertain || recording || Boolean(voiceBlob)
                    || !state || !state.can_reply || !state.voice_ready;
                stop.hidden = !voiceRun; cancel.hidden = !recording && !voiceBlob;
                voiceSend.hidden = !voiceBlob; voiceSend.disabled = !voiceBlob || sending || checking || uncertain || !state || !state.can_reply;
                if (voiceNote) {
                    var unsupported = available && state && (!state.voice_ready || !voiceMime());
                    voiceNote.textContent = unsupported ? labels.voice_unavailable : ''; voiceNote.hidden = !unsupported;
                }
            }
        }
        function request(url, key, body) {
            if (closed || denied || navigator.onLine === false || document.hidden) return Promise.reject(new Error('Unavailable'));
            if (controllers.has(key)) controllers.get(key).abort();
            var controller = new AbortController(); controllers.set(key, controller);
            var options = {credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
            if (body !== undefined) {
                options.method = 'POST'; options.headers['X-CSRF-TOKEN'] = config.csrf;
                if (typeof FormData !== 'undefined' && body instanceof FormData) options.body = body;
                else { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
            }
            return fetch(url, options).then(function (response) {
                if (response.redirected) { var expired = new Error('Unavailable'); expired.status = 401; throw expired; }
                if (!(response.headers.get('Content-Type') || '').includes('application/json')) {
                    var error = new Error('Unavailable'); error.status = response.status; throw error;
                }
                return response.json().then(function (data) {
                    if (!response.ok || !data || data.success !== true) {
                        var error = new Error('Unavailable'); error.status = response.status;
                        if (data && data.state === 'FAILED' && data.reply_id === null
                            && ['STALE_INBOUND', 'WINDOW_CLOSED', 'REPLIES_UNAVAILABLE', 'VOICE_UNAVAILABLE'].indexOf(data.reason) !== -1) {
                            error.safeFailure = true; error.reason = data.reason;
                        }
                        throw error;
                    }
                    return data;
                });
            }).finally(function () { if (controllers.get(key) === controller) controllers.delete(key); });
        }
        function failure(error, current, wasSending) {
            if (closed || current !== generation || error && error.name === 'AbortError') return;
            if (error && [401, 403, 419].indexOf(error.status) !== -1) {
                denied = true; clear('denied');
                if (window.DashboardWhatsAppInbox) window.DashboardWhatsAppInbox.destroy();
                note(labels.denied, true); panel.hidden = false;
            } else if (wasSending && (!error || error.status !== 422 && !error.safeFailure)) {
                uncertain = true; note(labels.unknown, true); controls();
            } else {
                if (wasSending) attempt = null;
                note(navigator.onLine === false ? labels.offline : error && error.reason === 'WINDOW_CLOSED' ? labels.expired
                    : wasSending ? labels.failed : labels.unavailable, true);
            }
        }
        function reason(data) {
            if (['SEND_UNCERTAIN', 'UNKNOWN_PENDING', 'UNKNOWN'].indexOf(data.reason) !== -1) return labels.unknown;
            if (['WINDOW_EXPIRED', 'WINDOW_CLOSED', 'OUTSIDE_WINDOW', 'NO_INBOUND'].indexOf(data.reason) !== -1) return labels.expired;
            if (['NOT_CONFIGURED', 'DISABLED'].indexOf(data.reason) !== -1) return labels.not_configured;
            return data.available && data.can_reply ? labels.ready : labels.unavailable;
        }
        function renderAudit(data) {
            if (!audit) return;
            auditList.textContent = '';
            var replies = Array.isArray(data.recent_replies) ? data.recent_replies.slice(0, 20) : [];
            replies.forEach(function (reply) {
                var item = document.createElement('li'), body = document.createElement('p');
                body.textContent = ['audio', 'voice'].indexOf(reply.kind) !== -1 ? labels.voice_message
                    : typeof reply.text === 'string' ? reply.text.slice(0, 4096) : '';
                var stateLabel = document.createElement('span');
                stateLabel.textContent = reply.state === 'ACCEPTED' ? labels.accepted : reply.state === 'FAILED' ? labels.failed : labels.unknown;
                item.appendChild(body); item.appendChild(stateLabel);
                var stamp = typeof reply.created_at === 'string' && /^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/.test(reply.created_at)
                    ? reply.created_at.replace(' ', 'T') + 'Z' : reply.created_at;
                var date = new Date(stamp);
                if (reply.created_at && !isNaN(date.getTime())) {
                    var time = document.createElement('time'); time.dateTime = date.toISOString();
                    time.textContent = date.toLocaleString(document.documentElement && document.documentElement.lang || 'ar');
                    item.appendChild(time);
                }
                auditList.appendChild(item);
            });
            audit.hidden = !replies.length;
        }
        function loadState(preserve) {
            if (!conversation || closed || denied || checking) return Promise.resolve();
            var selected = conversation, current = generation;
            var url = new URL(config.conversations_base_url + '/' + selected + '/reply-state', window.location.href);
            if (attempt) url.searchParams.set('client_request_id', attempt.id);
            checking = true; controls();
            return request(url.href, 'state').then(function (data) {
                if (closed || current !== generation || selected !== conversation) return;
                state = data;
                renderAudit(data);
                if (attempt && ['ACCEPTED', 'FAILED'].indexOf(data.request_state) !== -1) {
                    var accepted = data.request_state === 'ACCEPTED', wasText = attempt.kind === 'text'; uncertain = false; attempt = null;
                    if (accepted && wasText) { text.value = ''; count.textContent = '0 / 4096'; }
                    note(accepted ? labels.accepted : labels.failed, !accepted);
                } else if (uncertain || ['SEND_UNCERTAIN', 'UNKNOWN_PENDING'].indexOf(data.reason) !== -1 || data.request_state === 'UNKNOWN') {
                    uncertain = true; note(labels.unknown, true);
                } else if (!preserve) note(reason(data), !data.can_reply);
            }).catch(function (error) { failure(error, current, false); }).finally(function () {
                if (current === generation) { checking = false; controls(); }
            });
        }
        function choose(event) {
            var id = Number(event && event.detail && event.detail.conversation);
            if (!Number.isSafeInteger(id) || id < 1 || closed || denied || document.hidden || navigator.onLine === false) return;
            clear(); conversation = id; panel.hidden = false; note(labels.loading); loadState();
        }
        function uuid() {
            if (!window.crypto) return null;
            if (typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
            if (typeof window.crypto.getRandomValues !== 'function') return null;
            var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
            bytes[6] = bytes[6] & 15 | 64; bytes[8] = bytes[8] & 63 | 128;
            var hex = Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
            return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
        }
        function submit(event) {
            event.preventDefault(); controls();
            if (send.disabled || sending || uncertain || !state) return;
            var id = uuid(), inbound = Number(state.latest_inbound_id);
            if (!id || !Number.isSafeInteger(inbound) || inbound < 1) { note(labels.unavailable, true); return; }
            var selected = conversation, current = generation;
            attempt = {id: id, text: text.value.trim(), inbound: inbound, kind: 'text'};
            sendRequest(selected, current, 'replies', {client_request_id: attempt.id, text: attempt.text, expected_inbound_id: attempt.inbound}, 'text');
        }
        function sendRequest(selected, current, path, body, kind) {
            sending = true; controls(); note(labels.sending);
            request(config.conversations_base_url + '/' + selected + '/' + path, 'send', body).then(function (data) {
                if (closed || current !== generation || selected !== conversation) return;
                if (data.state === 'ACCEPTED') {
                    if (kind === 'text') { text.value = ''; count.textContent = '0 / 4096'; }
                    attempt = null; uncertain = false; note(labels.accepted);
                } else if (data.state === 'FAILED') {
                    attempt = null; uncertain = false; note(labels.failed, true);
                } else { uncertain = true; note(labels.unknown, true); }
            }).catch(function (error) { failure(error, current, true); }).finally(function () {
                if (current === generation) { sending = false; controls(); loadState(true); }
            });
        }
        function releaseStream(stream) {
            if (stream && typeof stream.getTracks === 'function') stream.getTracks().forEach(function (track) {
                if (track.readyState !== 'ended') track.stop();
            });
        }
        function clearVoice() {
            microphoneRequest++;
            var run = voiceRun; voiceRun = null;
            if (run) {
                run.discard = true; clearTimeout(run.limit); clearInterval(run.ticker);
                if (run.recorder && run.recorder.state !== 'inactive') { try { run.recorder.stop(); } catch (error) {} }
                releaseStream(run.stream); run.chunks = [];
            }
            voiceBlob = null;
            if (voiceUrl && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(voiceUrl);
            voiceUrl = null;
            if (preview) {
                if (typeof preview.pause === 'function') preview.pause();
                preview.removeAttribute('src'); preview.hidden = true;
                if (typeof preview.load === 'function') preview.load();
            }
            if (voiceTime) { voiceTime.textContent = ''; voiceTime.hidden = true; }
        }
        function voiceMime() {
            if (!window.MediaRecorder || typeof window.MediaRecorder.isTypeSupported !== 'function'
                || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') return null;
            var offered = state && Array.isArray(state.supported_voice_mime_types) ? state.supported_voice_mime_types
                : state && Array.isArray(state.voice_mime_types) ? state.voice_mime_types
                : ['audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4'];
            var allowed = ['audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4'];
            // The server advertises WebM only when its validated conversion path is ready.
            if (state && (state.voice_webm_ready === true || state.voice_ready === true
                && offered.indexOf('audio/webm;codecs=opus') !== -1)) allowed.push('audio/webm;codecs=opus', 'audio/webm');
            return offered.find(function (mime) { return allowed.indexOf(mime) !== -1 && window.MediaRecorder.isTypeSupported(mime); }) || null;
        }
        function startRecording() {
            if (!record || record.disabled || sending || uncertain) return;
            var mime = voiceMime();
            if (!mime) { note(labels.voice_unavailable, true); return; }
            var current = generation, selected = conversation, microphoneCurrent = ++microphoneRequest;
            openingMicrophone = true; controls();
            navigator.mediaDevices.getUserMedia({audio: true}).then(function (stream) {
                if (closed || current !== generation || selected !== conversation || microphoneCurrent !== microphoneRequest || !openingMicrophone) { releaseStream(stream); return; }
                openingMicrophone = false;
                var recorder;
                try { recorder = new window.MediaRecorder(stream, {mimeType: mime}); }
                catch (error) { releaseStream(stream); note(labels.voice_unavailable, true); controls(); return; }
                var run = {recorder: recorder, stream: stream, chunks: [], bytes: 0, discard: false,
                    current: current, conversation: selected, mime: mime, started: Date.now()};
                voiceRun = run;
                recorder.ondataavailable = function (event) {
                    if (run.discard || voiceRun !== run || !event.data || !event.data.size) return;
                    run.bytes += event.data.size;
                    if (run.bytes > 8 * 1024 * 1024) { clearVoice(); note(labels.voice_limit, true); controls(); return; }
                    run.chunks.push(event.data);
                };
                recorder.onerror = function () {
                    if (voiceRun === run) { clearVoice(); note(labels.voice_unavailable, true); controls(); }
                };
                recorder.onstop = function () {
                    clearTimeout(run.limit); clearInterval(run.ticker); releaseStream(run.stream);
                    if (run.discard || closed || run.current !== generation || run.conversation !== conversation || voiceRun !== run) { run.chunks = []; return; }
                    voiceRun = null;
                    voiceBlob = new Blob(run.chunks, {type: recorder.mimeType || run.mime}); run.chunks = [];
                    if (!voiceBlob.size || voiceBlob.size > 8 * 1024 * 1024 || typeof URL.createObjectURL !== 'function') {
                        clearVoice(); note(labels.voice_unavailable, true); controls(); return;
                    }
                    voiceUrl = URL.createObjectURL(voiceBlob); preview.src = voiceUrl; preview.hidden = false;
                    voiceTime.hidden = true; note(labels.voice_ready); controls();
                };
                try { recorder.start(1000); }
                catch (error) { clearVoice(); note(labels.voice_unavailable, true); controls(); return; }
                run.limit = setTimeout(function () { if (voiceRun === run) stopRecording(); }, 60000);
                run.ticker = setInterval(function () {
                    if (voiceRun !== run) return;
                    voiceTime.textContent = Math.min(60, Math.floor((Date.now() - run.started) / 1000)) + ' / 60';
                }, 1000);
                voiceTime.hidden = false; voiceTime.textContent = '0 / 60'; note(labels.recording); controls();
            }).catch(function () {
                if (current !== generation || closed || microphoneCurrent !== microphoneRequest) return;
                openingMicrophone = false; note(labels.microphone_denied, true); controls();
            });
        }
        function stopRecording() {
            if (!voiceRun || voiceRun.recorder.state === 'inactive') return;
            try { voiceRun.recorder.stop(); } catch (error) { clearVoice(); note(labels.voice_unavailable, true); controls(); }
        }
        function cancelRecording() { openingMicrophone = false; clearVoice(); note(state ? reason(state) : ''); controls(); }
        function sendRecording() {
            controls();
            if (!voiceSend || voiceSend.disabled || !voiceBlob || sending || uncertain || !state) return;
            var id = uuid(), inbound = Number(state.latest_inbound_id), selected = conversation, current = generation;
            if (!id || !Number.isSafeInteger(inbound) || inbound < 1 || typeof FormData === 'undefined') { note(labels.unavailable, true); return; }
            var body = new FormData(), mime = voiceBlob.type.toLowerCase();
            var extension = mime.indexOf('ogg') !== -1 ? 'ogg' : mime.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
            body.append('audio', voiceBlob, 'reply.' + extension); body.append('client_request_id', id);
            body.append('expected_inbound_id', String(inbound)); attempt = {id: id, inbound: inbound, kind: 'voice'};
            clearVoice(); sendRequest(selected, current, 'voice', body, 'voice');
        }
        function input() { count.textContent = text.value.length + ' / 4096'; controls(); }
        function refreshClick() { if (!refresh.disabled) loadState(false); }
        function reset(event) {
            var reason = event && event.detail && event.detail.reason;
            if (reason === 'denied') denied = true;
            clear(reason);
        }
        function updated(event) {
            if (conversation && Number(event.detail && event.detail.conversation) === conversation && !sending) loadState(uncertain);
        }
        form.addEventListener('submit', submit); text.addEventListener('input', input); refresh.addEventListener('click', refreshClick);
        if (record) {
            record.addEventListener('click', startRecording); stop.addEventListener('click', stopRecording);
            cancel.addEventListener('click', cancelRecording); voiceSend.addEventListener('click', sendRecording);
        }
        document.addEventListener('whatsapp:conversation-selected', choose);
        document.addEventListener('whatsapp:conversation-updated', updated);
        document.addEventListener('whatsapp:private-reset', reset);
        var session = {host: host, destroy: function () {
            if (closed) return; clear(); closed = true;
            form.removeEventListener('submit', submit); text.removeEventListener('input', input); refresh.removeEventListener('click', refreshClick);
            if (record) {
                record.removeEventListener('click', startRecording); stop.removeEventListener('click', stopRecording);
                cancel.removeEventListener('click', cancelRecording); voiceSend.removeEventListener('click', sendRecording);
            }
            document.removeEventListener('whatsapp:conversation-selected', choose);
            document.removeEventListener('whatsapp:conversation-updated', updated);
            document.removeEventListener('whatsapp:private-reset', reset);
            config.csrf = ''; controls();
        }};
        active = session;
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function () {
            if (active === session) destroy(); else session.destroy();
        });
        var selected = window.DashboardWhatsAppInbox && typeof window.DashboardWhatsAppInbox.selection === 'function'
            ? window.DashboardWhatsAppInbox.selection() : 0;
        if (selected) choose({detail: {conversation: selected}});
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardWhatsAppReplies = {mount: mount, destroy: destroy};
    document.addEventListener('dashboard:before-unload', destroy);
    document.addEventListener('dashboard:page-mounted', mount);
    mount();
}());
