<?php
// Compile actual Blade/CSS with isolated Laravel view classes, then inject fictional UI data.
// No application bootstrap, database, credentials, customer data or network access.
$repo = dirname(__DIR__);
$autoload = getenv('WA_INBOX_TEST_VENDOR') ?: '/tmp/wa-workflow-deps/vendor/autoload.php';
if (is_dir($autoload)) $autoload .= '/autoload.php';
if (PHP_SAPI !== 'cli' || !is_file($autoload) || !extension_loaded('dom')) exit(1);
require $autoload;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\Events\Dispatcher;
$temp = sys_get_temp_dir() . '/wa-static-preview-' . bin2hex(random_bytes(8));
mkdir($temp, 0700); mkdir($temp . '/cache', 0700); mkdir($temp . '/views/admin/whatsapp', 0700, true);
register_shutdown_function(static function () use ($temp) {
    $remove = static function ($path) use (&$remove) {
        if (is_dir($path) && !is_link($path)) { foreach (scandir($path) as $item) if ($item !== '.' && $item !== '..') $remove($path . '/' . $item); rmdir($path); }
        elseif (file_exists($path)) unlink($path);
    }; $remove($temp);
});
file_put_contents($temp . '/placeholder', '');
$container = new class($repo, $temp) extends Container {
    private $root; private $temp;
    public function __construct($root, $temp) { $this->root = $root; $this->temp = $temp; }
    public function getLocale() { return 'ar'; }
    public function publicPath($path = '') { $full = $this->root . '/public/' . ltrim($path, '/'); return is_file($full) ? $full : $this->temp . '/placeholder'; }
};
Container::setInstance($container);
mkdir($temp . '/public/css', 0700, true); mkdir($temp . '/public/js', 0700, true);
foreach (['css/dashboard-whatsapp-inbox.css', 'css/dashboard-whatsapp-orders.css', 'css/dashboard-whatsapp-replies.css',
    'js/dashboard-whatsapp-inbox.js', 'js/dashboard-whatsapp-orders.js', 'js/dashboard-whatsapp-replies.js'] as $asset) {
    if (is_file($repo . '/public/' . $asset)) symlink($repo . '/public/' . $asset, $temp . '/public/' . $asset);
    else file_put_contents($temp . '/public/' . $asset, '');
}
$container->instance('path.public', $temp . '/public');
$container->instance('url', new class {
    public function to($path, $extra = [], $secure = null) { return '/' . ltrim($path, '/'); }
    public function asset($path, $secure = null) { return '/' . ltrim($path, '/'); }
});
$container->alias('url', \Illuminate\Contracts\Routing\UrlGenerator::class);
$container->instance('session', new class { public function token() { return 'synthetic-preview-not-a-credential'; } });
file_put_contents($temp . '/views/admin/index.blade.php', '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>معاينة واتساب — بيانات مؤلفة</title>@stack("custom-css")</head><body>@yield("content")@stack("custom-js")</body></html>');
file_put_contents($temp . '/views/admin/whatsapp/orders.blade.php', '<section class="wa-orders"><h3>ملخص الطلب — مثال فقط</h3><p>بيانات مؤلفة لعرض مكان تحليل الطلب وبيانات العميل وإرساله للفرع. لا يوجد اتصال بالسيرفر.</p></section>');
$files = new Filesystem(); $compiler = new BladeCompiler($files, $temp . '/cache');
$resolver = new EngineResolver(); $resolver->register('blade', static fn() => new CompilerEngine($compiler));
$finder = new FileViewFinder($files, [$temp . '/views', $repo . '/resources/views']);
$view = new Factory($resolver, $finder, new Dispatcher($container)); $view->setContainer($container); $view->share('__env', $view);
$container->instance('view', $view);
$labels = ['title' => 'رسائل واتساب', 'subtitle' => 'صندوق فسخانستا — كل المحادثات في مكان واحد', 'loading' => 'جارٍ التحميل…',
    'refresh' => 'تحديث المحادثات', 'conversations' => 'المحادثات', 'messages' => 'الرسائل', 'central_scope' => 'الحساب المركزي',
    'choose_conversation' => 'اختر محادثة', 'back' => 'العودة للمحادثات', 'more_conversations' => 'محادثات أخرى', 'older_messages' => 'رسائل أقدم'];
$html = $view->make('admin.whatsapp.index', ['waInbox' => ['labels' => $labels, 'available' => true]])->render();
// Actual generated templates are PHP-linted as well as rendered.
foreach (glob($temp . '/cache/*.php') as $compiled) {
    exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($compiled), $lint, $exit);
    if ($exit !== 0) throw new RuntimeException('Preview Blade PHP lint failed.');
}
$doc = new DOMDocument('1.0', 'UTF-8'); libxml_use_internal_errors(true); $doc->loadHTML($html); libxml_clear_errors();
$xp = new DOMXPath($doc);
foreach (iterator_to_array($xp->query('//script|//link')) as $node) $node->parentNode->removeChild($node);
function previewNode($query): DOMElement { global $xp; $node = $xp->query($query)->item(0); if (!$node instanceof DOMElement) throw new RuntimeException('Missing rendered DOM hook: ' . $query); return $node; }
function previewText(DOMNode $node, string $text): void { global $doc; while ($node->firstChild) $node->removeChild($node->firstChild); $node->appendChild($doc->createTextNode($text)); }
function previewHtml(DOMNode $node, string $html): void {
    global $doc; $part = new DOMDocument('1.0', 'UTF-8'); @$part->loadHTML('<meta charset="utf-8"><div id="preview-fragment">' . $html . '</div>');
    $source = $part->getElementById('preview-fragment'); foreach (iterator_to_array($source->childNodes) as $child) $node->appendChild($doc->importNode($child, true));
}
$css = ''; $pins = [];
foreach (['public/css/dashboard-whatsapp-inbox.css', 'public/css/dashboard-whatsapp-replies.css',
    'resources/views/admin/whatsapp/index.blade.php', 'resources/views/admin/whatsapp/replies.blade.php'] as $path) {
    $bytes = file_get_contents($repo . '/' . $path); $pins[$path] = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
    if (str_ends_with($path, '.css')) $css .= $bytes . "\n";
}
$css = 'html,body{margin:0;background:#edf0ec;color:#213d37;font-family:Arial,Tahoma,sans-serif}button,textarea{font-family:inherit}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.preview-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:12px 16px;font-size:12px}.preview-bar strong{margin-inline-end:auto}.preview-bar button{border:1px solid #cbdacf;background:#fff;color:#295b42;border-radius:7px;padding:7px 10px;cursor:pointer}.preview-note{font-size:10px;color:#6d7c71}.preview-page.preview-mobile .wa-inbox-wrapper{max-width:390px;margin:auto}.preview-page:not(.preview-mobile) .wa-inbox-wrapper{max-width:1280px;margin:auto}.preview-page svg{width:1.1em;height:1.1em;vertical-align:middle}.preview-icon{display:inline-flex;align-items:center;justify-content:center}.preview-photo{margin:3px 0}.preview-audio{display:flex;align-items:center;gap:10px;font-size:12px}.preview-wave{height:28px;display:flex;align-items:center;gap:3px}.preview-wave span{width:3px;background:#649b76;border-radius:2px}.preview-footer{font-size:10px;color:#708273;text-align:center;padding:6px}' . $css;
$style = $doc->createElement('style'); $style->appendChild($doc->createTextNode($css)); previewNode('//head')->appendChild($style);
$csp = $doc->createElement('meta'); $csp->setAttribute('http-equiv', 'Content-Security-Policy');
$csp->setAttribute('content', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src data:; media-src data:; font-src 'none'; connect-src 'none'; form-action 'none'"); previewNode('//head')->appendChild($csp);
previewNode('//body')->setAttribute('class', 'preview-page');
$bar = $doc->createElement('div'); $bar->setAttribute('class', 'preview-bar');
previewHtml($bar, '<strong>معاينة واجهة · بيانات مؤلفة</strong><button type="button" data-preview-mobile>هاتف / كمبيوتر</button><button type="button" data-preview-quote>إظهار الرد المقتبس</button><button type="button" data-preview-selection>تحديد الرسائل</button><small class="preview-note">لا اتصال ولا إرسال ولا بيانات عملاء حقيقية</small>');
previewNode('//body')->insertBefore($bar, previewNode('//body')->firstChild);
$main = previewNode('//*[@id="whatsapp-inbox"]'); $main->setAttribute('class', $main->getAttribute('class') . ' has-selection');
previewText(previewNode('//*[@data-wa-connection]'), 'متصل · مثال');
previewText(previewNode('//*[@data-wa-count]'), '٨'); previewText(previewNode('//*[@data-wa-list-status]'), '');
previewText(previewNode('//*[@data-wa-title]'), 'عميل تجريبي ١'); previewText(previewNode('//*[@data-wa-customer]'), 'محادثة مؤلفة لعرض شكل الرسائل');
previewText(previewNode('//*[@data-wa-chat-status]'), ''); previewNode('//*[@data-wa-back]')->removeAttribute('hidden');
$threads = previewNode('//*[@data-wa-thread-list]');
for ($i = 1; $i <= 8; $i++) {
    $preview = $i === 1 ? 'هل ممكن أعرف الأصناف المتاحة اليوم؟' : ($i % 2 ? 'رسالة طويلة تجريبية تستعرض كيف يختصر السطر داخل قائمة المحادثات' : 'شكرًا، تمام 👍');
    previewHtml($threads, '<li><button type="button" class="wa-inbox-thread" aria-current="' . ($i === 1 ? 'true' : 'false') . '"><span class="wa-inbox-avatar">' . $i . '</span><span class="wa-inbox-thread-body"><span class="wa-inbox-thread-heading"><strong>عميل تجريبي ' . $i . '</strong><time>١٠:' . ($i + 10) . '</time></span><span class="wa-inbox-preview">' . $preview . '</span><span class="wa-inbox-phone">محادثة عرض فقط</span></span>' . ($i < 4 ? '<span class="wa-inbox-unread" aria-label="غير مقروءة"></span>' : '') . '</button></li>');
}
$messages = previewNode('//*[@data-wa-messages]');
$image = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480"><rect width="640" height="480" fill="#ebdfc4"/><rect x="40" y="35" width="560" height="410" rx="30" fill="#c9bfa4"/><ellipse cx="320" cy="250" rx="220" ry="145" fill="#f9f7ec"/><ellipse cx="320" cy="255" rx="180" ry="105" fill="#e6ebe0"/><path d="M180 270c55-100 220-100 280-25-45 80-200 105-280 25" fill="#b98349"/><path d="M203 249l80 18m-50-50l80 25m-20-45l80 25" stroke="#e4bd80" stroke-width="13" stroke-linecap="round"/><circle cx="403" cy="206" r="28" fill="#e1bf37"/><circle cx="400" cy="205" r="20" fill="#f2db79"/><g fill="#629650"><circle cx="193" cy="183" r="28"/><circle cx="172" cy="205" r="21"/><circle cx="205" cy="210" r="20"/></g><text x="320" y="425" text-anchor="middle" font-family="Arial" font-size="20" fill="#6c725b">FICTIONAL PREVIEW IMAGE</text></svg>';
$photo = 'data:image/svg+xml;base64,' . base64_encode($image);
$samples = [
    ['inbound', 'مساء الخير 👋', null], ['outbound', 'أهلًا بيك في فسخانستا! نقدر نساعدك تختار طلبك 😊', null],
    ['inbound', "هل ممكن أعرف الأصناف المتاحة اليوم؟\nوده مثال لرسالة عربية أطول مع كلمات English وأرقام ٢٣٠ لاختبار اتجاه النص ووضوح السطور.", null],
    ['outbound', "بالتأكيد. دي رسالة عرض فقط لتوضيح شكل الردود الطويلة.\nالأصناف والأسعار في هذا المثال مؤلفة؛ لم تُرسل الرسالة لأي شخص.", null],
    ['inbound', 'صورة توضيحية للمعاينة فقط', 'image'], ['outbound', 'تمام، هنراجع معاك تفاصيل الطلب.', 'quote'],
    ['inbound', 'مقطع صوتي تجريبي — لا يحتوي تسجيلًا', 'audio'], ['outbound', 'شكرًا لحضرتك 👍', null],
];
foreach ($samples as $i => [$direction, $text, $kind]) {
    $content = '<span class="wa-inbox-author">' . ($direction === 'inbound' ? 'عميل تجريبي' : 'فسخانستا · مثال') . '</span>';
    if ($kind === 'quote') $content .= '<div class="wa-quoted-message">هل ممكن أعرف الأصناف المتاحة اليوم؟</div>';
    if ($kind === 'image') $content .= '<div class="wa-media"><img class="wa-media-image" src="' . $photo . '" alt="رسم توضيحي مؤلف لطبق، ليس صورة عميل" width="640" height="480"></div>';
    if ($kind === 'audio') { $wave = ''; foreach ([8, 17, 25, 13, 20, 28, 12, 22, 15, 26, 10] as $height) $wave .= '<span style="height:' . $height . 'px"></span>'; $content .= '<div class="preview-audio"><button type="button" class="wa-inbox-button wa-icon-button" disabled aria-label="مقطع تجريبي غير قابل للتشغيل">▶</button><div class="preview-wave">' . $wave . '</div><span dir="ltr">0:18</span></div>'; }
    $content .= '<div class="wa-inbox-message-text">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div><time>١٠:' . ($i + 21) . '</time>';
    if ($i === 2) $content .= '<span class="wa-message-reaction" title="مثال لتفاعل قبله واتساب" aria-label="مثال لتفاعل قبله واتساب">👍</span>';
    $content .= '<button type="button" class="wa-bubble-action-trigger" data-preview-actions aria-label="خيارات رسالة تجريبية">⌄</button><div class="wa-bubble-actions-menu" hidden><button type="button" class="wa-bubble-action" data-preview-message-quote>↩ الرد</button><button type="button" class="wa-bubble-action">⧉ نسخ النص</button><button type="button" class="wa-bubble-action">↪ إعادة التوجيه</button><div class="wa-reaction-options"><button type="button">👍</button><button type="button">❤️</button><button type="button">😊</button></div><button type="button" class="wa-bubble-action">⌫ حذف من الداشبورد</button></div>';
    previewHtml($messages, '<li class="wa-inbox-message is-' . $direction . '"><label class="wa-message-selection-control" hidden><input type="checkbox" data-preview-check aria-label="تحديد رسالة تجريبية"></label><article class="wa-inbox-bubble">' . $content . '</article></li>');
}
previewNode('//*[@data-wa-replies]')->removeAttribute('hidden'); previewText(previewNode('//*[@data-wa-reply-status]'), 'معاينة فقط · Enter للإرسال وShift+Enter لسطر جديد');
foreach ($xp->query('//*[@data-wa-reply-text or @data-wa-reply-send or @data-wa-voice-record or @data-wa-reply-emoji]') as $button) $button->removeAttribute('disabled');
previewText(previewNode('//*[@data-wa-selection-count]'), 'لم يتم تحديد رسائل');
$paths = [
    'fa-whatsapp' => 'M4 20l1-4a8 8 0 1114 1 8 8 0 01-11 2z M9 7l2 3-1 1c1 2 2 3 4 4l1-1 3 2',
    'fa-microphone' => 'M9 4a3 3 0 016 0v8a3 3 0 01-6 0z M6 10v2a6 6 0 0012 0v-2 M12 18v4 M8 22h8',
    'fa-paper-plane' => 'M22 2L9 15 M22 2l-6 20-7-7-7-7z', 'fa-smile' => 'M20 12a8 8 0 11-16 0 8 8 0 0116 0 M8 9h1 M15 9h1 M8 14c2 3 6 3 8 0',
    'fa-sync-alt' => 'M4 10a8 8 0 0114-5l3 3 M21 3v5h-5 M20 14a8 8 0 01-14 5l-3-3 M3 21v-5h5',
    'fa-user' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0 M4 21v-3c0-6 16-6 16 0v3',
    'fa-check-square' => 'M20 13v7H4V4h12 M9 10l3 3 8-8', 'fa-trash-alt' => 'M4 7h16 M9 7V4h6v3 M6 7l1 14h10l1-14 M10 10v8 M14 10v8',
    'fa-arrow-right' => 'M4 12h16 M14 6l6 6-6 6', 'fa-arrow-down' => 'M12 4v16 M6 14l6 6 6-6',
    'fa-times' => 'M6 6l12 12 M18 6L6 18', 'fa-chevron-down' => 'M5 9l7 7 7-7', 'fa-clipboard-list' => 'M8 5H5v17h14V5h-3 M8 3h8v4H8z M8 11h8 M8 15h8 M8 19h5',
];
foreach (iterator_to_array($xp->query('//i')) as $icon) {
    $class = $icon->getAttribute('class'); $path = 'M5 12h14'; foreach ($paths as $name => $shape) if (str_contains($class, $name)) { $path = $shape; break; }
    $svg = $doc->createElementNS('http://www.w3.org/2000/svg', 'svg'); $svg->setAttribute('viewBox', '0 0 24 24'); $svg->setAttribute('aria-hidden', 'true'); $svg->setAttribute('fill', 'none'); $svg->setAttribute('stroke', 'currentColor'); $svg->setAttribute('stroke-width', '1.7'); $svg->setAttribute('stroke-linecap', 'round'); $svg->setAttribute('stroke-linejoin', 'round');
    $line = $doc->createElementNS('http://www.w3.org/2000/svg', 'path'); $line->setAttribute('d', $path); $svg->appendChild($line); $icon->appendChild($svg); $icon->setAttribute('class', $class . ' preview-icon');
}
$script = <<<'JS'
// Synthetic interactions only. No production module, fetch or microphone calls.
const q=s=>document.querySelector(s),qa=s=>Array.from(document.querySelectorAll(s));
const sourceRules=Array.from(document.styleSheets).flatMap(s=>Array.from(s.cssRules||[]));
const mobile=document.createElement('style');mobile.textContent=sourceRules.filter(r=>r.type===4&&/max-width:\s*720px/.test(r.conditionText)).flatMap(r=>Array.from(r.cssRules).map(v=>v.selectorText.split(',').map(s=>'.preview-mobile '+s.trim()).join(',')+'{'+v.style.cssText+'}')).join('\n');document.head.append(mobile);
const text=q('[data-wa-reply-text]');function resize(){text.style.height='auto';text.style.height=Math.min(120,Math.max(42,text.scrollHeight+2))+'px';q('[data-wa-reply-count]').textContent=Array.from(text.value).length+' / 4096';}
function quote(value){q('[data-wa-reply-quote-text]').textContent=value;q('[data-wa-reply-quote]').hidden=false;}
q('[data-preview-mobile]').onclick=()=>{document.body.classList.toggle('preview-mobile');};
q('[data-preview-quote]').onclick=()=>{if(q('[data-wa-reply-quote]').hidden)quote('هل ممكن أعرف الأصناف المتاحة اليوم؟');else q('[data-wa-reply-quote]').hidden=true;};
q('[data-wa-reply-quote-cancel]').onclick=()=>q('[data-wa-reply-quote]').hidden=true;
q('[data-wa-reply-emoji]').onclick=()=>{q('[data-wa-reply-emoji-picker]').hidden=!q('[data-wa-reply-emoji-picker]').hidden;};
qa('[data-wa-emoji]').forEach(b=>b.onclick=()=>{text.setRangeText(b.dataset.waEmoji,text.selectionStart,text.selectionEnd,'end');resize();q('[data-wa-reply-emoji-picker]').hidden=true;text.focus();});
text.oninput=resize;q('[data-wa-reply-form]').onsubmit=e=>{e.preventDefault();q('[data-wa-reply-status]').textContent='معاينة فقط — لم تُرسل أي رسالة';};
qa('[data-preview-actions]').forEach(b=>b.onclick=()=>{const menu=b.nextElementSibling;menu.hidden=!menu.hidden;if(!menu.hidden)menu.classList.toggle('is-above',q('[data-wa-scroll]').getBoundingClientRect().bottom-b.getBoundingClientRect().bottom<Math.min(280,menu.scrollHeight+36));});
qa('[data-preview-message-quote]').forEach(b=>b.onclick=()=>{quote(b.closest('.wa-inbox-bubble').querySelector('.wa-inbox-message-text').textContent);b.parentNode.hidden=true;text.focus();});
function selectMode(){const toolbar=q('[data-wa-selection-toolbar]');toolbar.hidden=!toolbar.hidden;qa('.wa-message-selection-control').forEach(n=>n.hidden=toolbar.hidden);}
q('[data-preview-selection]').onclick=selectMode;q('[data-wa-select-mode]').onclick=selectMode;q('[data-wa-selection-cancel]').onclick=selectMode;
function selected(){const count=qa('[data-preview-check]').filter(n=>n.checked).length;q('[data-wa-selection-count]').textContent=count+' رسائل مؤلفة محددة';q('[data-wa-delete-selected]').disabled=!count;qa('[data-preview-check]').forEach(n=>n.closest('li').classList.toggle('is-selected',n.checked));}
qa('[data-preview-check]').forEach(n=>n.onchange=selected);q('[data-wa-select-all]').onclick=()=>{qa('[data-preview-check]').forEach(n=>n.checked=true);selected();};
q('[data-wa-delete-selected]').onclick=()=>q('[data-wa-selection-count]').textContent='تأكيد الحذف مثال فقط — لا يوجد سيرفر';
q('[data-wa-back]').onclick=()=>q('#whatsapp-inbox').classList.remove('has-selection');qa('.wa-inbox-thread').forEach(b=>b.onclick=()=>q('#whatsapp-inbox').classList.add('has-selection'));
q('[data-wa-voice-record]').onclick=()=>{const b=q('[data-wa-voice-record]'),run=b.getAttribute('aria-pressed')==='true';b.setAttribute('aria-pressed',String(!run));q('[data-wa-voice-time]').hidden=run;q('[data-wa-voice-time]').textContent='0 / 60';q('[data-wa-voice-cancel]').hidden=false;q('[data-wa-voice-preview]').hidden=!run;q('[data-wa-reply-status]').textContent=run?'تسجيل تجريبي جاهز — لا يحتوي صوتًا':'حالة تسجيل مؤلفة — لم يُطلب إذن الميكروفون';};
q('[data-wa-voice-cancel]').onclick=()=>{q('[data-wa-voice-preview]').hidden=true;q('[data-wa-voice-time]').hidden=true;q('[data-wa-voice-cancel]').hidden=true;q('[data-wa-voice-record]').setAttribute('aria-pressed','false');};
resize();q('[data-wa-scroll]').scrollTop=999999;
JS;
$node = $doc->createElement('script'); $node->appendChild($doc->createTextNode('__WA_SYNTHETIC_PREVIEW_SCRIPT__')); previewNode('//body')->appendChild($node);
$comment = $doc->createComment('Actual source blobs: ' . json_encode($pins, JSON_UNESCAPED_SLASHES)); previewNode('//head')->appendChild($comment);
$output = $doc->saveHTML();
// HTML entities are not decoded inside script raw text. Insert only this fixed fixture script.
if (substr_count($output, '__WA_SYNTHETIC_PREVIEW_SCRIPT__') !== 1 || str_contains($script, '</script')) throw new RuntimeException('Invalid fixed preview script.');
$output = str_replace('__WA_SYNTHETIC_PREVIEW_SCRIPT__', $script, $output);
if (strlen($output) > 81920 || preg_match('/<script[^>]+src=|<link[^>]+href=|src="https?:|\bfetch\s*\(|getUserMedia\s*\(/', $output)) throw new RuntimeException('Preview must remain bounded and self-contained.');
foreach (['data-wa-reply-quote', 'data-wa-reply-emoji', 'data-wa-voice-record', 'data-wa-select-mode', 'wa-media-image', 'wa-quoted-message', 'preview-mobile'] as $hook) if (!str_contains($output, $hook)) throw new RuntimeException('Preview fixture missing ' . $hook);
$target = __DIR__ . '/fixtures/whatsapp-dashboard-preview.html'; if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
file_put_contents($target, $output);
echo 'WHATSAPP_DASHBOARD_PREVIEW_RENDERED bytes=' . strlen($output) . ' views=2 source_blobs=' . json_encode($pins, JSON_UNESCAPED_SLASHES) . "\n";
