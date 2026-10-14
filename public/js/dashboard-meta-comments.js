(function () {
    'use strict';
    if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
    if (window.DashboardMetaComments) { window.DashboardMetaComments.mount(); return; }

    var active = null, draftStores = new Map(), sendStores = new Map();
    function mount() {
        if (window.DashboardSPA && !window.DashboardSPA.isCurrentPage()) return;
        var host = document.getElementById('meta-comments'), bootstrap = document.getElementById('meta-comments-bootstrap');
        if (!host || !bootstrap || active && active.host === host) return;
        destroy();
        var config; try { config = JSON.parse(bootstrap.textContent); } catch (_) { return; }
        var labels = config.labels || {}, urls = config.urls || {}, origin = new URL(window.location.href).origin;
        var fields = {};
        ['account','alert','posts','post-count','post-status','more-posts','comments','comment-status','more-comments','post-title',
            'post-link','scroll','form','reply-text','reply-target','reply-preview','public','send','send-label','send-status','letter-count',
            'emoji-toggle','emoji-picker','emoji-grid','emoji-tabs','emoji-wrap','refresh'].forEach(function (name) {
            fields[name] = host.querySelector('[data-mc-' + name + ']');
        });
        if (Object.keys(fields).some(function (name) { return !fields[name]; })) return;
        var accounts = [], channel = '', account = null, posts = new Map(), comments = new Map(), replyPages = new Map();
        var post = null, selectedKey = '', postCursor = null, commentCursor = null, epoch = 0, postEpoch = 0;
        var closed = false, denied = false, sending = false, recovering = false, controllers = new Map(), busy = new Set(), timer = null, timeoutIds = new Set();
        var viewer = '', drafts = new Map(), sends = new Map();
        var postSnapshots = new Map(), commentSnapshots = new Map();
        var commentsPaint = '', liveRunning = false, liveFailures = 0, liveReplyTurn = 0, liveReplyLast = '', liveAlert = '';
        var emojiCategory = 'reactions', caret = {start:0,end:0};
        var emojiGroups = {
            reactions:[['👍','إعجاب','Like'],['❤️','حب','Love'],['😂','ضحك','Laugh'],['😮','دهشة','Wow'],['😢','حزين','Sad'],['😡','غاضب','Angry'],['🙏','شكر ودعاء','Thanks'],['😍','إعجاب وحب','Heart eyes'],
                ['😀','ابتسامة عريضة','Grinning'],['😊','ابتسامة لطيفة','Smiling'],['🙂','مبتسم','Smile'],['😁','ضحكة','Beaming'],['🤣','ضحك شديد','Rolling laugh'],['😉','غمزة','Wink'],['😘','قبلة','Kiss'],['🥰','حب وامتنان','Affection']],
            hearts:[['👋','تحية','Wave'],['👌','تمام','Okay'],['✌️','سلام','Peace'],['🤝','مصافحة','Handshake'],['👏','تصفيق','Applause'],['🙌','احتفال','Celebration'],['💪','قوة','Strong'],['🤲','دعاء','Prayer'],
                ['🤗','حضن','Hug'],['🫶','قلب باليدين','Heart hands'],['🤍','قلب أبيض','White heart'],['💛','قلب أصفر','Yellow heart'],['💙','قلب أزرق','Blue heart'],['💜','قلب بنفسجي','Purple heart']],
            extras:[['✅','علامة صح','Check'],['❌','علامة خطأ','Cross'],['⭐','نجمة','Star'],['✨','لمعة','Sparkles'],['🎉','احتفال','Party'],['🎊','زينة احتفال','Confetti'],['💯','مائة بالمائة','Hundred'],['🔥','نار','Fire'],
                ['🌹','وردة','Rose'],['💐','باقة ورد','Bouquet'],['☀️','شمس','Sun'],['🌙','هلال','Moon'],['😋','لذيذ','Delicious'],['🤤','أكل شهي','Tasty'],['🍽️','طبق طعام','Plate'],['🐟','سمكة','Fish'],
                ['🍋','ليمون','Lemon'],['🥗','سلطة','Salad'],['🍞','خبز','Bread'],['🚚','توصيل','Delivery'],['📍','موقع','Location'],['⏰','وقت','Time'],['💬','تعليق','Comment'],['📞','تليفون','Phone'],['💰','نقود','Money'],['🛍️','تسوق','Shopping']]
        };
        function node(tag, cls, value) { var el = document.createElement(tag); if (cls) el.className = cls; if (value !== undefined) el.textContent = String(value); return el; }
        function label(name) { return labels[name] || name; }
        function text(value, limit) { return typeof value === 'string' ? value.slice(0, limit || 20000) : ''; }
        function author(row) { return text(typeof row.author === 'string' ? row.author : row.author && (row.author.name || row.author.username), 160) || label('anonymous'); }
        function identity(row) { return row && typeof row.key === 'string' && /^[a-f0-9]{64}$/i.test(row.key) ? row.key : ''; }
        function rows(value) { return Array.isArray(value) ? value.slice(0,100).filter(function(row) { return identity(row) && typeof row.handle === 'string' && row.handle.length < 8192; }) : []; }
        function cursor(value) { return typeof value === 'string' && value.length < 8192 && value ? value : null; }
        function date(value) { var time = new Date(value); return value && !isNaN(time.getTime()) ? time.toLocaleString(config.locale || 'ar', {day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}) : ''; }
        function sourceUrl(value) {
            try { var url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password && (!url.port || url.port === '443')
                && ['facebook.com','instagram.com'].some(function(domain) { return url.hostname === domain || url.hostname.endsWith('.' + domain); }) ? url.href : null; }
            catch (_) { return null; }
        }
        function endpoint(name, params) {
            var url = new URL(urls[name] || '/admin/meta/comments/' + name, window.location.href);
            if (url.origin !== origin || !/^\/admin\/meta\/comments\//.test(url.pathname) || url.username || url.password) throw new Error(label('unavailable'));
            Object.keys(params || {}).forEach(function(key) { if (params[key] !== null && params[key] !== undefined && params[key] !== '') url.searchParams.set(key, String(params[key])); });
            return url.href;
        }
        function bindViewer(value) {
            var nextViewer = /^[a-f0-9]{64}$/i.test(String(value || '')) ? String(value) : '';
            if (viewer && viewer !== nextViewer) { postSnapshots.clear(); commentSnapshots.clear(); }
            viewer = nextViewer;
            if (!viewer) return;
            if (!draftStores.has(viewer)) draftStores.set(viewer,new Map());
            if (!sendStores.has(viewer)) {
                var recovered = new Map();
                try { var saved = JSON.parse(window.sessionStorage.getItem('meta-comments-pending:' + viewer) || '[]');
                    if (Array.isArray(saved)) saved.slice(0,100).forEach(function(item) {
                        if (Array.isArray(item) && /^[a-f0-9]{64}$/i.test(item[0]) && item[1] && ['SENDING','UNKNOWN'].indexOf(item[1].state) !== -1)
                            recovered.set(item[0],{state:'UNKNOWN',message:text(item[1].message,2000),request_id:text(item[1].request_id,64),account_label:text(item[1].account_label,160)});
                    });
                } catch (_) {}
                sendStores.set(viewer,recovered);
            }
            drafts = draftStores.get(viewer); sends = sendStores.get(viewer);
        }
        function persistSends() {
            if (!viewer) return;
            try { window.sessionStorage.setItem('meta-comments-pending:' + viewer,JSON.stringify(Array.from(sends.entries()).filter(function(entry) {
                return ['SENDING','UNKNOWN'].indexOf(entry[1].state) !== -1;
            }).slice(-100))); } catch (_) {}
        }
        function alert(message) { fields.alert.textContent = message || ''; fields.alert.hidden = !message; }
        function errorMessage(error) {
            var payload = error && error.payload || {}, message = text(payload.message,800) || (error && error.status === 403 ? label('denied') : label('unavailable'));
            var permissions = Array.isArray(payload.required_permissions) ? payload.required_permissions.filter(function(p) { return typeof p === 'string' && /^[a-z_]+$/.test(p); }).slice(0,8) : [];
            return message + (permissions.length ? ' · ' + label('permissions') + ': ' + permissions.join(', ') : '');
        }
        function abortRead(key,controller) { controller.abort(); clearTimeout(controller.commentsTimer); timeoutIds.delete(controller.commentsTimer); controllers.delete(key); busy.delete(key); }
        function abortReads() { controllers.forEach(function(controller,key) { if (key !== 'reply') abortRead(key,controller); }); }
        function accessLost(error) { return error && (error.status === 401 || ['ACCESS_DENIED','CENTRAL_ONLY'].indexOf((error.payload || {}).reason) !== -1); }
        async function request(key,name,params,payload) {
            if (controllers.has(key)) controllers.get(key).abort();
            var controller = new AbortController(), timeout;
            controllers.set(key,controller); busy.add(key);
            try {
                timeout = setTimeout(function() { controller.commentsTimedOut = true; controller.abort(); }, payload ? 30000 : 20000); controller.commentsTimer = timeout; timeoutIds.add(timeout);
                var options = {method:payload ? 'POST' : 'GET',credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal,
                    headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}};
                if (payload) { options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-TOKEN'] = config.csrf || ''; options.body = JSON.stringify(payload); }
                var response = await fetch(endpoint(name,params),options), data;
                if (controller.signal.aborted) { var canceled = new Error('Canceled'); canceled.name = 'AbortError'; throw canceled; }
                if (response.redirected) throw new Error(label('unavailable'));
                var contentType = response.headers && response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) throw new Error(label('unavailable'));
                try { data = await response.json(); } catch (_) { throw new Error(label('unavailable')); }
                if (controller.signal.aborted) { var interrupted = new Error('Canceled'); interrupted.name = 'AbortError'; throw interrupted; }
                if (!data || typeof data !== 'object') throw new Error(label('unavailable'));
                if (payload && ['ACCEPTED','UNKNOWN','FAILED'].indexOf(data.state) !== -1) return data;
                if (!response.ok || data.success !== true) {
                    var failure = new Error(errorMessage({status:response.status,payload:data})); failure.payload = data; failure.status = response.status; throw failure;
                }
                return data;
            } catch (error) {
                if (controller.commentsTimedOut) error.commentsTimeout = true;
                throw error;
            } finally {
                clearTimeout(timeout); timeoutIds.delete(timeout);
                if (controllers.get(key) === controller) { controllers.delete(key); busy.delete(key); }
            }
        }
        function renderChannels() { host.querySelectorAll('[data-mc-channel]').forEach(function(button) { button.setAttribute('aria-pressed',button.dataset.mcChannel === channel ? 'true' : 'false'); }); }
        function renderAccounts() {
            fields.account.textContent = '';
            var visible = accounts.filter(function(row) { return !channel || row.channel === channel; });
            visible.forEach(function(row) { var option = node('option','',row.label); option.value = row.account_key; fields.account.appendChild(option); });
            if (!visible.length) { var empty = node('option','',label('select_account')); empty.value = ''; fields.account.appendChild(empty); }
            fields.account.disabled = !visible.length || denied;
            var retained = visible.find(function(row) { return account && row.account_key === account.account_key; });
            fields.account.value = retained ? retained.account_key : visible.length ? visible[0].account_key : '';
            chooseAccount(fields.account.value);
        }
        function renderPosts() {
            fields.posts.textContent = '';
            posts.forEach(function(row,key) {
                var item = node('li'), button = node('button','mc-post-button'); button.type = 'button'; button.dataset.mcPost = key;
                button.setAttribute('aria-pressed',post && identity(post) === key ? 'true' : 'false');
                var meta = node('span','mc-post-meta'); meta.appendChild(node('span','mc-channel-badge ' + (account && account.channel || ''),label(account && account.channel || 'facebook')));
                meta.appendChild(node('time','mc-date',date(row.created_at))); button.appendChild(meta);
                button.appendChild(node('span','mc-post-caption',text(row.text) || label('no_caption')));
                var foot = node('span','mc-post-foot');
                var amount = Number(row.comments_count); if (Number.isFinite(amount) && amount >= 0) foot.appendChild(node('span','',String(amount) + ' ' + label('count')));
                foot.appendChild(node('span','',label('comments') + ' ←')); button.appendChild(foot); item.appendChild(button); fields.posts.appendChild(item);
            });
            fields['post-count'].textContent = String(posts.size);
            fields['more-posts'].hidden = !postCursor; fields['more-posts'].disabled = busy.has('posts');
        }
        function commentCard(row,key,nested) {
            var item = node('li',nested ? 'mc-reply' : 'mc-comment' + (selectedKey === key ? ' is-selected' : ''));
            if (!nested) item.dataset.mcComment = key;
            var meta = node('div','mc-comment-meta'); meta.appendChild(node('span','mc-comment-author',author(row))); meta.appendChild(node('time','mc-date',date(row.created_at))); item.appendChild(meta);
            var content = node('p','mc-comment-text',text(row.text)); content.setAttribute('dir','auto'); item.appendChild(content);
            if (nested) return item;
            var actions = node('div','mc-comment-actions');
            var reply = node('button','mc-button',label('reply')); reply.type = 'button'; reply.dataset.mcReply = key; reply.disabled = row.can_reply === false || denied; actions.appendChild(reply);
            var count = Number(row.replies_count) || 0, page = replyPages.get(key);
            if (count > 0 || page || row.replies_count === null || row.replies_count === undefined) {
                var toggle = node('button','mc-button mc-secondary',(page && page.open ? label('hide_replies') : label('show_replies')) + (count ? ' (' + count + ')' : ''));
                toggle.type = 'button'; toggle.dataset.mcToggleReplies = key; toggle.setAttribute('aria-expanded',page && page.open ? 'true' : 'false'); actions.appendChild(toggle);
            }
            item.appendChild(actions);
            if (page && page.open) {
                var section = node('div','mc-replies'), list = node('ol','mc-reply-list');
                if (page.status) { var status = node('p','mc-status mc-replies-status',page.status); status.setAttribute('role','status'); section.appendChild(status); }
                page.rows.forEach(function(child,childKey) { list.appendChild(commentCard(child,childKey,true)); }); section.appendChild(list);
                if (page.cursor) { var more = node('button','mc-button mc-secondary',label('more_replies')); more.type = 'button'; more.dataset.mcMoreReplies = key; more.disabled = busy.has('replies:' + key); section.appendChild(more); }
                item.appendChild(section);
            }
            var pending = sends.get(key);
            if (pending && ['SENDING','UNKNOWN'].indexOf(pending.state) !== -1) {
                var pendingSection = node('div','mc-replies'), pendingList = node('ol','mc-reply-list'), pendingRow = node('li','mc-reply mc-pending');
                pendingRow.dataset.mcPending = key; pendingRow.dataset.mcState = pending.state;
                pendingRow.appendChild(node('strong','mc-comment-author',pending.account_label || account && account.label || ''));
                var pendingText = node('p','mc-comment-text',pending.message || ''); pendingText.setAttribute('dir','auto'); pendingRow.appendChild(pendingText);
                var pendingStatus = node('p','mc-pending-status'); pendingStatus.setAttribute('role','status');
                pendingStatus.appendChild(node('i',pending.state === 'SENDING' ? 'far fa-clock' : 'fas fa-exclamation-circle'));
                pendingStatus.appendChild(node('span','',label(pending.state === 'SENDING' ? 'sending' : 'unknown')));
                pendingRow.appendChild(pendingStatus); pendingList.appendChild(pendingRow); pendingSection.appendChild(pendingList); item.appendChild(pendingSection);
            }
            return item;
        }
        function renderComments() {
            fields['more-comments'].hidden = !commentCursor; fields['more-comments'].disabled = busy.has('comments');
            function visibleRow(row) { return [identity(row),author(row),text(row.text),date(row.created_at),row.can_reply !== false,row.replies_count]; }
            var paint = JSON.stringify([selectedKey,denied,Array.from(comments.entries()).map(function(entry) {
                var key = entry[0], page = replyPages.get(key), pending = sends.get(key);
                return [visibleRow(entry[1]),page ? [page.open,page.status,!!page.cursor,page.open ? Array.from(page.rows.values()).map(visibleRow) : []] : null,
                    pending && ['SENDING','UNKNOWN'].indexOf(pending.state) !== -1 ? [pending.state,pending.message,pending.account_label] : null];
            })]);
            // Handles/cursors are renewed on every read. Their opaque bytes do not change the visible cards.
            if (paint === commentsPaint) return;
            commentsPaint = paint;
            var priorScroll = fields.scroll.scrollTop, anchor = null, anchorTop = 0;
            if (priorScroll > 2 && fields.scroll.getBoundingClientRect) {
                var viewport = fields.scroll.getBoundingClientRect();
                Array.from(fields.comments.children).some(function(item) {
                    var rect = item.getBoundingClientRect();
                    if (rect.bottom > viewport.top && rect.top < viewport.bottom) { anchor = item.dataset.mcComment; anchorTop = rect.top; return true; }
                    return false;
                });
            }
            fields.comments.textContent = '';
            comments.forEach(function(row,key) { fields.comments.appendChild(commentCard(row,key,false)); });
            fields.scroll.scrollTop = priorScroll;
            if (anchor) {
                var retained = Array.from(fields.comments.children).find(function(item) { return item.dataset.mcComment === anchor; });
                if (retained) fields.scroll.scrollTop = priorScroll + retained.getBoundingClientRect().top - anchorTop;
            }
        }
        function mergeComments(incoming,retain) {
            var updated = retain ? new Map(comments) : new Map();
            incoming.forEach(function(row) { updated.set(identity(row),row); });
            // A fresh head belongs above the older pages already loaded by the operator.
            return new Map(Array.from(updated.entries()).sort(function(left,right) {
                var a = Date.parse(left[1].created_at), b = Date.parse(right[1].created_at);
                return Number.isFinite(a) && Number.isFinite(b) ? b - a : 0;
            }));
        }
        function saveDraft() { if (selectedKey) drafts.set(selectedKey,fields['reply-text'].value); }
        function rememberCaret() {
            var input = fields['reply-text'], length = input.value.length;
            caret = {start:Number.isFinite(input.selectionStart) ? input.selectionStart : length,end:Number.isFinite(input.selectionEnd) ? input.selectionEnd : length};
        }
        function closeEmoji(refocus) {
            fields['emoji-picker'].hidden = true; fields['emoji-toggle'].setAttribute('aria-expanded','false');
            if (refocus && !fields['reply-text'].disabled) {
                fields['reply-text'].focus(); fields['reply-text'].setSelectionRange(caret.start,caret.end);
            }
        }
        function renderEmoji() {
            fields['emoji-tabs'].textContent = ''; fields['emoji-grid'].textContent = '';
            Object.keys(emojiGroups).forEach(function(group) {
                var tab = node('button','',label('emoji_' + group)); tab.type = 'button'; tab.dataset.mcEmojiCategory = group;
                tab.setAttribute('aria-pressed',group === emojiCategory ? 'true' : 'false'); fields['emoji-tabs'].appendChild(tab);
            });
            emojiGroups[emojiCategory].forEach(function(item) {
                var button = node('button','',item[0]); button.type = 'button'; button.dataset.mcEmoji = item[0];
                button.setAttribute('aria-label',item[0] + ' · ' + item[config.locale === 'ar' ? 1 : 2]); button.title = item[config.locale === 'ar' ? 1 : 2]; fields['emoji-grid'].appendChild(button);
            });
        }
        function insertEmoji(value) {
            if (fields['reply-text'].disabled || !emojiGroups[emojiCategory].some(function(item) { return item[0] === value; })) return;
            var input = fields['reply-text'], start = Math.min(caret.start,input.value.length), end = Math.max(start,Math.min(caret.end,input.value.length));
            var next = input.value.slice(0,start) + value + input.value.slice(end);
            if (Array.from(next).length > 1000) { fields['send-status'].textContent = label('limit'); return; }
            input.value = next; caret = {start:start + value.length,end:start + value.length};
            closeEmoji(true); saveDraft(); renderComposer();
        }
        function renderComposer() {
            var selected = comments.get(selectedKey), state = sends.get(selectedKey), locked = state && ['SENDING','UNKNOWN'].indexOf(state.state) !== -1;
            fields['reply-target'].textContent = selected ? label('reply_to') + ' ' + author(selected) : label('choose_comment');
            fields['reply-preview'].hidden = !selected; fields['reply-preview'].textContent = selected ? text(selected.text,300) : '';
            fields.public.textContent = account ? label('public') + ' ' + account.label : label('public_hint');
            fields['reply-text'].disabled = !selected || selected.can_reply === false || locked || denied || closed;
            var overLimit = Array.from(fields['reply-text'].value).length > 1000;
            fields.send.disabled = fields['reply-text'].disabled || sending || recovering || busy.has('reply') || !viewer || !fields['reply-text'].value.trim() || overLimit || navigator.onLine === false;
            fields['emoji-toggle'].disabled = fields['reply-text'].disabled;
            fields.refresh.disabled = sending || denied;
            fields.account.disabled = sending || denied || !accounts.some(function(row) { return !channel || row.channel === channel; });
            host.querySelectorAll('[data-mc-channel]').forEach(function(button) { button.disabled = sending || denied; });
            if (fields['reply-text'].disabled) closeEmoji(false);
            fields['send-label'].textContent = state && state.state === 'SENDING' ? label('sending') : label('send');
            fields['letter-count'].textContent = Array.from(fields['reply-text'].value).length + ' / 1000';
            fields['send-status'].textContent = state ? state.state === 'SENDING' ? label('sending') : state.state === 'UNKNOWN' ? label('unknown') : state.state === 'ACCEPTED' ? label('accepted') : state.error || label('failed')
                : overLimit ? label('limit') : selected && selected.can_reply === false ? label('no_reply') : navigator.onLine === false ? label('offline') : '';
        }
        function boundedSnapshot(store,key,value) {
            store.delete(key); store.set(key,value);
            while (store.size > 20) store.delete(store.keys().next().value);
        }
        function rememberPosts() {
            if (account && posts.size) boundedSnapshot(postSnapshots,account.account_key,
                {rows:new Map(Array.from(posts.entries()).slice(0,120)),cursor:posts.size <= 120 ? postCursor : null});
        }
        function rememberComments() {
            if (account && post && comments.size) boundedSnapshot(commentSnapshots,account.account_key + ':' + identity(post),
                {rows:new Map(Array.from(comments.entries()).slice(0,120)),cursor:comments.size <= 120 ? commentCursor : null,selected:selectedKey});
        }
        function accountRows(value) {
            return Array.isArray(value) ? value.slice(0,100).filter(function(row) {
                return row && /^[a-f0-9]{64}$/i.test(String(row.account_key || '')) && ['facebook','instagram'].indexOf(row.channel) !== -1;
            }).map(function(row) { return {account_key:row.account_key,channel:row.channel,label:text(row.label,160) || label(row.channel)}; }) : [];
        }
        function resetPost() {
            saveDraft(); rememberComments(); closeEmoji(false); postEpoch++; post = null; selectedKey = ''; commentCursor = null; comments.clear(); replyPages.clear();
            controllers.forEach(function(controller,key) { if (key === 'comments' || key.indexOf('replies:') === 0) abortRead(key,controller); });
            fields['reply-text'].value = ''; fields['post-title'].textContent = label('choose_post'); fields['post-link'].hidden = true;
            fields['post-link'].removeAttribute('href'); fields['comment-status'].textContent = label('choose_post'); fields['comment-status'].hidden = false;
            host.classList.remove('has-post'); renderComments(); renderComposer();
            clearTimeout(timer); liveFailures = 0; liveReplyTurn = 0; liveReplyLast = '';
        }
        function chooseAccount(key) {
            var next = accounts.find(function(row) { return row.account_key === key; }) || null;
            if (account && next && account.account_key === next.account_key) return;
            rememberPosts(); epoch++; abortReads(); resetPost(); account = next; posts.clear(); postCursor = null;
            var snapshot = next && postSnapshots.get(next.account_key);
            if (snapshot) { posts = new Map(snapshot.rows); postCursor = snapshot.cursor; }
            renderPosts(); fields['post-status'].hidden = posts.size > 0; fields['post-status'].textContent = next ? label('loading') : label('empty_accounts');
            if (next) loadPosts(false);
        }
        async function loadAccounts() {
            if (sending) return;
            var currentEpoch = ++epoch; alert(''); fields.account.disabled = true;
            try {
                var data = await request('accounts','accounts'); if (closed || sending || currentEpoch !== epoch) return;
                bindViewer(data.viewer_key || config.viewer_key);
                accounts = accountRows(data.accounts);
                renderAccounts();
            } catch (error) {
                if (closed || currentEpoch !== epoch || error.name === 'AbortError') return;
                alert(errorMessage(error)); fields['post-status'].textContent = errorMessage(error); if (accessLost(error)) deny();
            }
        }
        async function loadPosts(more) {
            if (!account || closed || denied || sending || busy.has('posts')) return;
            var currentEpoch = epoch, chosen = account.account_key;
            fields['post-status'].hidden = posts.size > 0; fields['post-status'].textContent = label('loading'); fields['more-posts'].disabled = true;
            try {
                var data = await request('posts','posts',{account_key:chosen,cursor:more ? postCursor : null}); if (closed || sending || currentEpoch !== epoch) return;
                var incoming = rows(data.posts), updated = more ? posts : new Map(); incoming.forEach(function(row) { updated.set(identity(row),row); });
                posts = updated; postCursor = cursor(data.next_cursor);
                rememberPosts();
                if (post && posts.has(identity(post))) post = posts.get(identity(post));
                fields['post-status'].hidden = posts.size > 0; fields['post-status'].textContent = label('empty_posts'); renderPosts(); alert('');
            } catch (error) {
                if (closed || currentEpoch !== epoch || error.name === 'AbortError') return;
                fields['post-status'].hidden = false; fields['post-status'].textContent = errorMessage(error); if (accessLost(error)) deny();
            } finally { if (!closed && currentEpoch === epoch) fields['more-posts'].disabled = false; }
        }
        function choosePost(key) {
            var next = posts.get(key); if (!next) return;
            if (post && identity(post) === key) { host.classList.add('has-post'); poll(); return; }
            resetPost(); post = next; host.classList.add('has-post'); fields['post-title'].textContent = text(post.text,240) || label('no_caption');
            var link = sourceUrl(post.permalink); fields['post-link'].hidden = !link; if (link) fields['post-link'].href = link;
            var snapshot = account && commentSnapshots.get(account.account_key + ':' + key);
            if (snapshot) {
                comments = new Map(snapshot.rows); commentCursor = snapshot.cursor; selectedKey = snapshot.selected || '';
                fields['reply-text'].value = drafts.get(selectedKey) || ''; renderComments(); renderComposer();
            }
            fields['comment-status'].hidden = comments.size > 0; fields['comment-status'].textContent = label('loading'); renderPosts(); loadComments(false,!!snapshot); poll();
        }
        function expiredSelection(error) { return ['INVALID_HANDLE','ACCOUNT_CHANGED'].indexOf((error && error.payload || {}).reason) !== -1; }
        async function recoverSelection(expectedEpoch,commentKey) {
            if (!account || !post) return false;
            var chosen = account.account_key, chosenPost = identity(post), data, incoming, renewed, nextCursor = null;
            function current() { return !closed && !sending && expectedEpoch === postEpoch && account && account.account_key === chosen && post && identity(post) === chosenPost; }
            saveDraft(); recovering = true; renderComposer();
            try {
                // Refresh a selected older post/comment without using any expired cursor or handle.
                // Provider pagination is bounded; a missing target requires an explicit new selection.
                for (var page = 0; page < 5; page++) {
                    data = await request('posts','posts',{account_key:chosen,cursor:nextCursor});
                    if (!current()) return false;
                    incoming = rows(data.posts); incoming.forEach(function(row) { posts.set(identity(row),row); });
                    renewed = incoming.find(function(row) { return identity(row) === chosenPost; });
                    nextCursor = cursor(data.next_cursor); if (renewed || !nextCursor) break;
                }
                if (!renewed) return false;
                post = renewed; postCursor = nextCursor; rememberPosts(); renderPosts();
                if (!commentKey) return true;
                nextCursor = null;
                for (var commentPage = 0; commentPage < 5; commentPage++) {
                    data = await request('comments','comments',{post_handle:post.handle,cursor:nextCursor});
                    if (!current()) return false;
                    incoming = rows(data.comments); incoming.forEach(function(row) { comments.set(identity(row),row); });
                    renewed = incoming.find(function(row) { return identity(row) === commentKey; });
                    nextCursor = cursor(data.next_cursor); if (renewed || !nextCursor) break;
                }
                commentCursor = nextCursor; rememberComments(); renderComments(); renderComposer();
                return !!renewed;
            } finally { recovering = false; if (!closed) { renderComposer(); poll(); } }
        }
        async function loadComments(more,quiet,refreshReplies,recovered) {
            if (!post || closed || denied || sending || busy.has('comments')) return;
            var currentEpoch = postEpoch, handle = post.handle;
            if (!quiet) { fields['comment-status'].hidden = comments.size > 0; fields['comment-status'].textContent = label('loading'); }
            fields['more-comments'].disabled = true;
            try {
                var data = await request('comments','comments',{post_handle:handle,cursor:more ? commentCursor : null}); if (closed || sending || currentEpoch !== postEpoch) return;
                var hadRows = comments.size > 0;
                comments = mergeComments(rows(data.comments),more || quiet);
                if (more || !quiet || !hadRows) commentCursor = cursor(data.next_cursor);
                rememberComments();
                fields['comment-status'].hidden = comments.size > 0; fields['comment-status'].textContent = label('empty_comments'); renderComments(); renderComposer();
                if (refreshReplies) {
                    var expanded = Array.from(replyPages.keys()).filter(function(key) { return replyPages.get(key).open && comments.has(key); }).slice(0,5);
                    for (var expandedKey of expanded) {
                        if (closed || sending || currentEpoch !== postEpoch) break;
                        await loadReplies(expandedKey,false,true);
                    }
                }
                if (!quiet) alert('');
                return true;
            } catch (error) {
                if (closed || currentEpoch !== postEpoch || error.name === 'AbortError' && !error.commentsTimeout) return;
                if (!recovered && expiredSelection(error)) {
                    try { if (await recoverSelection(currentEpoch,selectedKey || null)) return await loadComments(false,quiet,refreshReplies,true); }
                    catch (recoveryError) { error = recoveryError; if (closed || currentEpoch !== postEpoch || error.name === 'AbortError') return; }
                }
                if (quiet) { liveAlert = errorMessage(error); alert(liveAlert); } else { fields['comment-status'].hidden = false; fields['comment-status'].textContent = errorMessage(error); }
                if (accessLost(error)) deny();
                return false;
            } finally { if (!closed && currentEpoch === postEpoch) fields['more-comments'].disabled = false; if (!liveRunning) poll(); }
        }
        async function loadReplies(key,more,quiet,recovered) {
            var row = comments.get(key), page = replyPages.get(key), requestKey = 'replies:' + key;
            if (!row || !page || closed || denied || sending || busy.has(requestKey)) return;
            var currentEpoch = postEpoch; if (!quiet) { page.status = label('loading'); renderComments(); }
            try {
                var data = await request(requestKey,'replies',{comment_handle:row.handle,cursor:more ? page.cursor : null}); if (closed || sending || currentEpoch !== postEpoch) return;
                var wasLoaded = page.loaded;
                if (!more && !quiet) page.rows.clear(); rows(data.replies).forEach(function(child) { page.rows.set(identity(child),child); });
                if (more || !quiet || !wasLoaded) page.cursor = cursor(data.next_cursor);
                page.loaded = true; page.status = ''; renderComments(); return true;
            } catch (error) {
                if (closed || currentEpoch !== postEpoch || error.name === 'AbortError' && !error.commentsTimeout) return;
                if (!recovered && expiredSelection(error)) {
                    try { if (await recoverSelection(currentEpoch,key)) return await loadReplies(key,false,quiet,true); }
                    catch (recoveryError) { error = recoveryError; if (closed || currentEpoch !== postEpoch || error.name === 'AbortError') return; }
                }
                page.status = errorMessage(error); renderComments(); if (accessLost(error)) deny();
                return false;
            } finally { if (!liveRunning) poll(); }
        }
        function chooseComment(key) {
            if (!comments.has(key)) return; saveDraft(); closeEmoji(false); selectedKey = key;
            var state = sends.get(key); fields['reply-text'].value = drafts.get(key) || (state && state.state === 'UNKNOWN' ? state.message : '') || '';
            caret = {start:fields['reply-text'].value.length,end:fields['reply-text'].value.length}; fields['reply-text'].setSelectionRange(caret.start,caret.end);
            renderComments(); renderComposer(); if (!fields['reply-text'].disabled) fields['reply-text'].focus();
        }
        function uuid() {
            if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
            if (!window.crypto || !window.crypto.getRandomValues) throw new Error(label('failed'));
            var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); bytes[6] = bytes[6] & 15 | 64; bytes[8] = bytes[8] & 63 | 128;
            var hex = Array.from(bytes).map(function(value) { return value.toString(16).padStart(2,'0'); }).join('');
            return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
        }
        async function sendReply(event) {
            event.preventDefault();
            var key = selectedKey, row = comments.get(key), message = fields['reply-text'].value.trim(), previous = sends.get(key);
            if (!key || !row || !viewer || row.can_reply === false || closed || denied || sending || recovering || busy.has('reply') || !message || Array.from(message).length > 1000
                || previous && ['SENDING','UNKNOWN'].indexOf(previous.state) !== -1) return;
            if (navigator.onLine === false) { renderComposer(); return; }
            saveDraft(); var requestId; try { requestId = uuid(); } catch (error) { sends.set(key,{state:'FAILED',error:label('failed')}); renderComposer(); return; }
            var accountLabel = account && account.label || '', sendEpoch = postEpoch, renewFailedSelection = false;
            sending = true; clearTimeout(timer); closeEmoji(false); abortReads();
            replyPages.forEach(function(page) { if (page.status === label('loading')) page.status = ''; });
            if (comments.size) fields['comment-status'].hidden = true;
            if (posts.size) fields['post-status'].hidden = true;
            sends.set(key,{state:'SENDING',request_id:requestId,message:message,account_label:accountLabel}); persistSends(); renderComments(); renderComposer();
            try {
                var data = await request('reply','reply',null,{comment_handle:row.handle,message:message,client_request_id:requestId});
                var state = data.state === 'ACCEPTED' && data.success === true ? 'ACCEPTED' : data.state === 'FAILED' ? 'FAILED' : 'UNKNOWN';
                renewFailedSelection = state === 'FAILED' && ['INVALID_HANDLE','ACCOUNT_CHANGED','RECONNECT_REQUIRED'].indexOf(data.reason) !== -1;
                sends.set(key,{state:state,request_id:requestId,message:message,account_label:accountLabel,error:state === 'FAILED' ? errorMessage({payload:Object.assign({message:label('failed')},data)}) : ''}); persistSends();
                if (!closed && ['ACCESS_DENIED','CENTRAL_ONLY'].indexOf(data.reason) !== -1) { alert(errorMessage({payload:data})); deny(); return; }
                if (state === 'ACCEPTED') {
                    drafts.delete(key);
                    if (account) {
                        postSnapshots.delete(account.account_key);
                        commentSnapshots.forEach(function(_,snapshotKey) { if (snapshotKey.indexOf(account.account_key + ':') === 0) commentSnapshots.delete(snapshotKey); });
                    }
                    if (data.reply && identity(data.reply)) { var page = replyPages.get(key) || {open:true,rows:new Map(),cursor:null,status:''}; page.open = true; page.rows.set(identity(data.reply),data.reply); replyPages.set(key,page); }
                    if (!closed && selectedKey === key) fields['reply-text'].value = '';
                }
                if (!closed) { renderComments(); renderComposer(); }
            } catch (error) {
                // Only an explicit FAILED result proves that Meta did not accept a write.
                // A timeout, navigation, or unexpected HTTP result can still have published it.
                sends.set(key,{state:'UNKNOWN',request_id:requestId,message:message,account_label:accountLabel}); persistSends();
                if (!closed) { renderComments(); renderComposer(); }
            } finally {
                sending = false;
                if (!closed) {
                    if (renewFailedSelection && sendEpoch === postEpoch) {
                        try {
                            if (await recoverSelection(sendEpoch,key)) {
                                var failed = sends.get(key);
                                if (failed && failed.state === 'FAILED') failed.error = label('selection_renewed');
                            }
                        } catch (recoveryError) { if (!closed && sendEpoch === postEpoch) { alert(errorMessage(recoveryError)); if (accessLost(recoveryError)) deny(); } }
                    }
                    renderComposer(); poll();
                }
            }
        }
        function deny() {
            denied = true; epoch++; postEpoch++; abortReads(); resetPost(); accounts = []; account = null; posts.clear();
            postSnapshots.clear(); commentSnapshots.clear();
            fields.account.textContent = ''; fields.account.disabled = true; fields['post-status'].hidden = false; fields['post-status'].textContent = label('denied');
            drafts.clear(); renderPosts(); renderComposer();
        }
        function refresh() {
            if (closed || denied || sending) return;
            if (navigator.onLine === false) { alert(label('offline')); return; }
            alert(''); postSnapshots.clear(); commentSnapshots.clear();
            if (!accounts.length) loadAccounts(); else { loadPosts(false); if (post) loadComments(false,true,true); }
        }
        function poll() {
            clearTimeout(timer);
            if (!liveReady() || liveRunning) return;
            timer = setTimeout(liveCycle,Math.min(30000,5000 * Math.pow(2,liveFailures)));
        }
        function liveReady() { return !closed && !denied && !sending && !recovering && !document.hidden && navigator.onLine !== false && post && host.classList.contains('has-post'); }
        function nextLiveReply() {
            var expanded = Array.from(replyPages.keys()).filter(function(key) { return replyPages.get(key).open && comments.has(key); });
            if (!expanded.length) return null;
            var chosen = expanded.indexOf(selectedKey) !== -1 ? selectedKey : '';
            if (chosen && (liveReplyLast !== chosen || expanded.length === 1)) { liveReplyLast = chosen; return chosen; }
            var others = expanded.filter(function(key) { return key !== chosen; });
            liveReplyLast = others[liveReplyTurn++ % others.length]; return liveReplyLast;
        }
        async function liveCycle() {
            clearTimeout(timer);
            if (!liveReady() || liveRunning) return;
            if (busy.size) { poll(); return; }
            liveRunning = true;
            var currentEpoch = postEpoch, result;
            try {
                result = await loadComments(false,true);
                if (result === false) liveFailures = Math.min(liveFailures + 1,3);
                if (result !== true || !liveReady() || currentEpoch !== postEpoch || busy.size) return;
                var key = nextLiveReply();
                if (key) {
                    result = await loadReplies(key,false,true);
                    if (result === false) liveFailures = Math.min(liveFailures + 1,3);
                }
                if (result === true) {
                    liveFailures = 0;
                    if (liveAlert && fields.alert.textContent === liveAlert) alert('');
                    liveAlert = '';
                }
            } finally { liveRunning = false; poll(); }
        }
        function resumeLive() {
            liveFailures = 0;
            if (liveReady()) liveCycle();
            else if (!closed && !denied && !sending && !document.hidden && navigator.onLine !== false && !busy.size) {
                if (!accounts.length) loadAccounts(); else if (!post) loadPosts(false);
            }
        }
        function visibility() { if (document.hidden) { clearTimeout(timer); abortReads(); } else resumeLive(); }
        function connectivity() {
            renderComposer();
            if (navigator.onLine === false) { clearTimeout(timer); abortReads(); alert(label('offline')); }
            else { alert(''); resumeLive(); }
        }
        function click(event) {
            var emoji = event.target.closest('[data-mc-emoji]'); if (emoji && host.contains(emoji)) { insertEmoji(emoji.dataset.mcEmoji); return; }
            var category = event.target.closest('[data-mc-emoji-category]'); if (category && host.contains(category) && emojiGroups[category.dataset.mcEmojiCategory]) {
                emojiCategory = category.dataset.mcEmojiCategory; renderEmoji(); return;
            }
            if (event.target.closest('[data-mc-emoji-close]')) { closeEmoji(true); return; }
            if (event.target.closest('[data-mc-emoji-toggle]')) {
                if (!fields['emoji-toggle'].disabled) { rememberCaret(); var open = fields['emoji-picker'].hidden; fields['emoji-picker'].hidden = !open; fields['emoji-toggle'].setAttribute('aria-expanded',open ? 'true' : 'false'); }
                return;
            }
            var target = event.target.closest('[data-mc-channel]');
            if (target && host.contains(target)) { if (!sending) { channel = target.dataset.mcChannel || ''; renderChannels(); renderAccounts(); } return; }
            target = event.target.closest('[data-mc-post]'); if (target && host.contains(target)) { if (!sending) choosePost(target.dataset.mcPost); return; }
            target = event.target.closest('[data-mc-reply]'); if (target && host.contains(target)) { chooseComment(target.dataset.mcReply); return; }
            target = event.target.closest('[data-mc-toggle-replies]'); if (target && host.contains(target)) {
                var key = target.dataset.mcToggleReplies, page = replyPages.get(key); if (!page) { page = {open:true,rows:new Map(),cursor:null,status:''}; replyPages.set(key,page); loadReplies(key,false); }
                else { page.open = !page.open; if (page.open && !page.rows.size) loadReplies(key,false); } renderComments(); return;
            }
            target = event.target.closest('[data-mc-more-replies]'); if (target && host.contains(target)) { loadReplies(target.dataset.mcMoreReplies,true); return; }
            if (event.target.closest('[data-mc-more-posts]')) loadPosts(true);
            if (event.target.closest('[data-mc-more-comments]')) loadComments(true,false);
            if (event.target.closest('[data-mc-refresh]')) refresh();
            if (event.target.closest('[data-mc-back]')) { saveDraft(); closeEmoji(false); host.classList.remove('has-post'); clearTimeout(timer); abortReads(); }
        }
        function input() { rememberCaret(); saveDraft(); renderComposer(); }
        function change() { if (!sending) chooseAccount(fields.account.value); }
        function outsideEmoji(event) { if (!fields['emoji-wrap'].contains(event.target)) closeEmoji(false); }
        function keydown(event) { if (event.key === 'Escape' && !fields['emoji-picker'].hidden) { event.preventDefault(); closeEmoji(true); } }
        function emojiPointer(event) { if (event.target.closest('[data-mc-emoji-toggle]') || event.target.closest('[data-mc-emoji]')) event.preventDefault(); }
        host.addEventListener('click',click); fields.account.addEventListener('change',change); fields.form.addEventListener('submit',sendReply);
        fields['reply-text'].addEventListener('input',input); document.addEventListener('visibilitychange',visibility);
        ['select','click','keyup'].forEach(function(name) { fields['reply-text'].addEventListener(name,rememberCaret); });
        host.addEventListener('mousedown',emojiPointer); document.addEventListener('click',outsideEmoji); document.addEventListener('keydown',keydown);
        window.addEventListener('online',connectivity); window.addEventListener('offline',connectivity);
        var session = {host:host,destroy:function() {
            if (closed) return; saveDraft(); closeEmoji(false); closed = true; epoch++; postEpoch++; clearTimeout(timer);
            controllers.forEach(function(controller) { controller.abort(); }); controllers.clear(); timeoutIds.forEach(clearTimeout); timeoutIds.clear();
            host.removeEventListener('click',click); fields.account.removeEventListener('change',change); fields.form.removeEventListener('submit',sendReply);
            fields['reply-text'].removeEventListener('input',input); document.removeEventListener('visibilitychange',visibility);
            ['select','click','keyup'].forEach(function(name) { fields['reply-text'].removeEventListener(name,rememberCaret); });
            host.removeEventListener('mousedown',emojiPointer); document.removeEventListener('click',outsideEmoji); document.removeEventListener('keydown',keydown);
            window.removeEventListener('online',connectivity); window.removeEventListener('offline',connectivity);
        }};
        active = session; bindViewer(config.viewer_key); renderChannels(); renderEmoji(); renderComposer();
        if (Array.isArray(config.accounts)) { accounts = accountRows(config.accounts); renderAccounts(); }
        else loadAccounts();
        poll();
        if (window.DashboardSPA && window.DashboardSPA.onCleanup) window.DashboardSPA.onCleanup(function() { if (active === session) destroy(); else session.destroy(); });
    }
    function destroy() { if (active) active.destroy(); active = null; }
    window.DashboardMetaComments = {mount:mount,destroy:destroy,dispose:destroy};
    document.addEventListener('dashboard:before-unload',destroy); document.addEventListener('dashboard:page-mounted',mount); mount();
}());
