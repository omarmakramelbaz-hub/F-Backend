(function () {
    'use strict';
    document.querySelectorAll('[data-desktop-installer-upload]').forEach(function (form) {
        if (form.dataset.bound) return;
        form.dataset.bound = '1';
        var input = form.querySelector('input[type=file]');
        var button = form.querySelector('button[type=submit]');
        var status = form.querySelector('[data-upload-status]');
        var progress = form.querySelector('progress');
        var download = form.querySelector('[data-upload-download]');
        var busy = false;
        if (window.DashboardSPA) window.DashboardSPA.onBeforeLeave(function () {
            if (busy) status.textContent = 'الرفع مستمر. انتظر اكتماله قبل الانتقال إلى صفحة أخرى.';
            return !busy;
        });

        function leave(event) { if (busy) { event.preventDefault(); event.returnValue = ''; } }
        async function post(url, body) {
            for (var attempt = 0; attempt < 3; attempt++) {
                try {
                    var response = await fetch(url, { method: 'POST', credentials: 'same-origin', body: body,
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value } });
                    var json = await response.json().catch(function () { return {}; });
                    if (!response.ok) {
                        var message = response.status === 419 || response.status === 401 ? 'انتهت جلسة الدخول. حدّث الصفحة وسجّل الدخول ثم أعد الرفع.'
                            : response.status === 403 ? 'حسابك لا يملك صلاحية رفع ملف البرنامج.'
                            : json.message || 'تعذر الرفع. حاول مرة أخرى.';
                        var error = new Error(message);
                        error.stop = response.status < 500;
                        throw error;
                    }
                    return json;
                } catch (error) {
                    if (error.name === 'AbortError') error.stop = true;
                    if (!error.stop && attempt === 2) throw new Error('تعذر الاتصال. تأكد من الإنترنت ثم أعد الرفع.');
                    if (error.stop || attempt === 2) throw error;
                    status.textContent = 'الاتصال انقطع مؤقتًا. جاري إعادة المحاولة…';
                    await new Promise(function (resolve) { setTimeout(resolve, 1000 * (attempt + 1)); });
                }
            }
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy) return;
            var file = input.files[0];
            if (!file || file.size !== Number(form.dataset.bytes) || !/\.exe$/i.test(file.name)) {
                status.textContent = 'اختر ملف Fasakhansta-POS-Setup.exe الذي تم تنزيله من هنا.';
                return;
            }
            busy = true; input.disabled = true; button.disabled = true; download.hidden = true;
            progress.hidden = false; progress.value = 0; status.textContent = 'جاري بدء الرفع…';
            window.addEventListener('beforeunload', leave);
            try {
                var begin = new FormData(); begin.append('size', String(file.size));
                // Starting is not retried: each attempt owns a fresh server-side upload session.
                var response = await fetch(form.dataset.start, { method: 'POST', credentials: 'same-origin', body: begin,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value } });
                var upload = await response.json().catch(function () { return {}; });
                if (!response.ok) throw new Error(response.status === 419 ? 'انتهت جلسة الدخول. حدّث الصفحة ثم أعد الرفع.' : upload.message || 'تعذر بدء الرفع. حاول مرة أخرى.');
                if (!/^[0-9a-f]{64}$/.test(upload.upload_id) || upload.chunk_bytes !== 524288) throw new Error('تعذر بدء الرفع. حدّث الصفحة ثم أعد المحاولة.');
                for (var offset = 0; offset < file.size; offset += upload.chunk_bytes) {
                    var end = Math.min(offset + upload.chunk_bytes, file.size);
                    var part = new FormData(); part.append('upload_id', upload.upload_id); part.append('offset', String(offset));
                    part.append('chunk', file.slice(offset, end), 'chunk.bin');
                    var result = await post(form.dataset.chunk, part);
                    if (result.offset !== end) throw new Error('تعذر التأكد من اكتمال الرفع. أعد المحاولة.');
                    progress.value = Math.floor(end / file.size * 100);
                    status.textContent = 'جاري رفع البرنامج: ' + progress.value + '٪. اترك الصفحة مفتوحة.';
                }
                status.textContent = 'اكتمل الرفع. جاري فحص الملف…';
                var finish = new FormData(); finish.append('upload_id', upload.upload_id);
                var done = await post(form.dataset.finish, finish);
                download.href = done.download_url; download.hidden = false;
                status.textContent = 'تم رفع البرنامج بنجاح. التحميل متاح الآن لباقي الأجهزة من الداشبورد.';
                input.value = '';
            } catch (error) { status.textContent = error.stop ? error.message : error.message || 'تعذر الرفع. تأكد من الإنترنت ثم أعد المحاولة.'; }
            finally { busy = false; input.disabled = false; button.disabled = false; window.removeEventListener('beforeunload', leave); }
        });
    });
}());
