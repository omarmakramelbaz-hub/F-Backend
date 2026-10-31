'use strict';
// Pages the device shows inside the dashboard while offline when a page was never opened with the internet on it.

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));

const STYLE = `.goerp-offline-page{max-width:640px;margin:48px auto;padding:32px 28px;border:1px solid #243445;border-radius:16px;background:#0b1724;
color:#e8eef6;text-align:center;font-family:Almarai,Tahoma,sans-serif;box-shadow:0 18px 50px #0006}
.goerp-offline-page i.goerp-offline-icon{display:inline-grid;place-items:center;width:64px;height:64px;border-radius:50%;background:#fd720122;color:#fd7201;font-size:28px}
.goerp-offline-page h1{margin:18px 0 8px;font-size:22px;color:#fff}.goerp-offline-page p{margin:0 0 20px;color:#a9b8c8;line-height:1.8}
.goerp-offline-page nav{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.goerp-offline-page a,.goerp-offline-page button{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;border:1px solid #fd7201;
background:#fd7201;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}
.goerp-offline-page a.secondary{background:transparent;color:#fd7201}`;

/** Inside the dashboard (its own page switch): keeps the sidebar and header; only the page area shows this. */
function missingSpaPage(user, links) {
  const nav = links.map((link, index) => `<a href="${escape(link.href)}"${index ? ' class="secondary"' : ''}>${escape(link.label)}</a>`).join('');
  // The dashboard's page switch keeps a page's own styles between these two markers.
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>الصفحة تحتاج إنترنت</title>
<!-- dashboard-page-css:start --><style>${STYLE}</style><!-- dashboard-page-css:end --></head>
<body data-dashboard-actor="${escape(user.id + ':' + user.account_type)}"><nav class="main-header" hidden aria-hidden="true"></nav>
<div id="dashboard-page" data-dashboard-page style="display:contents"><div class="content-wrapper"><section class="content">
<div class="goerp-offline-page" role="status"><i class="fas fa-wifi goerp-offline-icon" aria-hidden="true"></i>
<h1>الصفحة دي محتاجة إنترنت</h1><p>مفيش نسخة منها على الجهاز لسه. هتتحفظ أول مرة تفتحها والنت شغال.<br>الكاشير وباقي الصفحات المحفوظة شغالة عادي.</p>
<nav>${nav}</nav></div></section></div></div></body></html>`;
}

/** A whole window load with nothing saved for this account yet: offers the device's own screens. */
function nothingSavedPage() {
  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>GO ERP</title><style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#091320;font-family:Almarai,Tahoma,sans-serif}${STYLE}</style></head>
<body><main class="goerp-offline-page" role="status"><i class="goerp-offline-icon" aria-hidden="true">⚡</i>
<h1>صفحات الداشبورد لسه متحفظتش على الجهاز للحساب ده</h1>
<p>بتتحفظ أول مرة الحساب يفتح البرنامج والنت شغال. لحد ما النت يرجع اشتغل من شاشات الجهاز، وكل حاجة هتتزامن مع الداشبورد لوحدها.</p>
<nav><button type="button" onclick="window.postMessage({goerp:'device-screens'},'*')">شاشات الجهاز</button></nav></main></body></html>`;
}

/** The dashboard's sign-in page asked for offline: the app itself signs out or back in. */
function signedOutPage() {
  return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>تسجيل الدخول</title></head><body></body></html>';
}

module.exports = { missingSpaPage, nothingSavedPage, signedOutPage };
