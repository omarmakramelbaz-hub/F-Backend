'use strict';
// The dashboard's GO opening on its home page and the GO in its sidebar (the dashboard's hologram release
// hologram-energy-20261101-v5), shipped with the app in ./hologram: the release's own files, unchanged.
// A page saved on this device before the dashboard got that look opens offline in the look the dashboard has now;
// pages saved since already have it and are left as they are.
const fs = require('node:fs');
const path = require('node:path');

const FOLDER = path.join(__dirname, 'hologram');
// Where the pages this device opens offline load the shipped files from; the app answers these addresses itself. The
// same folders as the dashboard's own files, so its in-place page switch treats them alike (a page's script under
// /dashboard/js/ runs again on every visit of the page).
const PREFIX = '/go-erp-desktop/dashboard/';
const SERVED = {
  'js/go-hologram.js': 'application/javascript; charset=utf-8',
  'js/go-hologram-shell.js': 'application/javascript; charset=utf-8',
  'css/go-hologram.css': 'text/css; charset=utf-8',
  'js/home-hud.js': 'application/javascript; charset=utf-8',
  'css/home-hud.css': 'text/css; charset=utf-8',
};
// The opening's sections (App\Services\Dashboard\HomeHologramMetrics): key, title, what is counted.
const SECTIONS = [['employees', 'الموظفين والرواتب', 'موظف'], ['customers', 'العملاء', 'عميل'], ['expenses', 'المصروفات', 'مصروف معتمد'],
  ['drawer', 'تقفيل الوردية', 'فروع للتقفيل'], ['stock', 'مخزون البضاعة', 'صنف'], ['ch-app', 'طلبات التطبيق', 'غير منتهية'],
  ['ch-takeaway', 'تيك أواي', 'إجمالي الطلبات'], ['ch-phone', 'دليفري', 'غير منتهية'], ['ch-dine', 'صالة', 'غير منتهية'],
  ['pending-expenses', 'مصروفات منتظرة', 'بانتظار الاعتماد']];

const texts = new Map();
const read = (name) => {
  if (!texts.has(name)) texts.set(name, fs.readFileSync(path.join(FOLDER, name), 'utf8'));
  return texts.get(name);
};
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));

/** One of the shipped files, by its address. */
function hologramFile(pathname) {
  if (!pathname.startsWith(PREFIX)) return null;
  const name = pathname.slice(PREFIX.length);
  if (!Object.prototype.hasOwnProperty.call(SERVED, name)) return null;
  return { contentType: SERVED[name], body: fs.readFileSync(path.join(FOLDER, path.basename(name))) };
}

/** Where the element starting at `start` ends (after its closing tag), counting the same elements inside it. */
function elementEnd(html, start, tag) {
  const pattern = new RegExp(`<(/?)${tag}\\b[^>]*>`, 'gi');
  pattern.lastIndex = start;
  let depth = 0;
  for (let found = pattern.exec(html); found; found = pattern.exec(html)) {
    if (found[1]) depth--;
    else if (!found[0].endsWith('/>')) depth++;
    if (depth === 0) return pattern.lastIndex;
  }
  return -1;
}

/**
 * The layout's part (every page): the GO in the sidebar in place of the earlier GO ERP logo, its styles, and the
 * hologram scripts after the dashboard's own, as the dashboard's layout has them. Only a page with the earlier logo.
 */
function currentShell(html, origin) {
  if (html.includes('data-go-sidebar-robot')) return html;
  const brand = html.indexOf('class="brand-link dashboard-brand"');
  if (brand < 0) return html;
  const logo = html.indexOf('<svg', brand);
  const copy = html.indexOf('dashboard-brand-copy', brand);
  if (logo < 0 || (copy >= 0 && logo > copy)) return html;
  const open = html.slice(logo, html.indexOf('>', logo) + 1);
  if (!/\bdashboard-brand-logo\b/.test(open) || !/\bdata-go-erp-solar=/.test(open)) return html;
  const end = elementEnd(html, logo, 'svg');
  const scripts = html.indexOf('<div data-dashboard-page-scripts');
  const head = html.indexOf('</head>');
  if (end < 0 || scripts < end || head < 0 || head > logo) return html;
  const robot = read('sidebar.html').trim().replace("{{ url('/admin/dashboard') }}", () => escape(origin + '/admin/dashboard'));
  const files = `<script src="${PREFIX}js/go-hologram.js"></script>\n<script src="${PREFIX}js/go-hologram-shell.js"></script>\n`;
  return html.slice(0, head) + `<link rel="stylesheet" href="${PREFIX}css/go-hologram.css">\n` + html.slice(head, logo) + robot
    + html.slice(end, scripts) + files + html.slice(scripts);
}

/** The home page's start-up data (the dashboard's bootstrap JSON), or null. */
function homeBoot(html) {
  const found = /<script type="application\/json" id="(home-overview|branch-home)-bootstrap">([\s\S]*?)<\/script>/.exec(html);
  if (!found) return null;
  try {
    const boot = JSON.parse(found[2]);
    return boot && typeof boot === 'object' ? { boot, start: found.index, text: found[0], id: found[1] } : null;
  } catch {
    return null;
  }
}

/** The opening's sections this account opens, without counts (shown as unavailable) when none were saved. */
function sectionsOnly(links) {
  const all = { ...(links || {}) };
  if (all.expenses) all['pending-expenses'] = all.expenses;
  const cards = SECTIONS.filter(([key]) => typeof all[key] === 'string').map(([key, label, countLabel]) => ({ key, label, url: all[key],
    count: null, count_label: countLabel, secondary_count: null, secondary_label: null, warning: false, available: false }));
  return { cards, scope: 'branch', scope_label: '', branch_count: 0, updated_at: null };
}

/** JSON inside a page's script tag, written the way the dashboard writes it (@json). */
const scriptJson = (value) => JSON.stringify(value).replace(/[<>&'\u2028\u2029]/g, (char) => '\\u' + char.charCodeAt(0).toString(16).padStart(4, '0'));

/**
 * The home page's part: the GO opening of the release in place of the earlier one (its solar system), with its
 * styles and script. counters: the opening's last counts saved on this device (or a function giving them, or null).
 */
function currentHome(html, name, counters) {
  const start = html.indexOf('<section id="go-hud"');
  if (start < 0) return html;
  const end = elementEnd(html, start, 'section');
  if (end < 0) return html;
  const earlier = html.slice(start, end);
  if (earlier.includes('data-hud-robot') || !earlier.includes('data-hud-core')) return html;
  const css = /(<link\b[^>]*\bhref=")[^"]*\/dashboard\/css\/home-hud\.css(?:\?[^"]*)?(")/;
  const js = /(<script\b[^>]*\bsrc=")[^"]*\/dashboard\/js\/home-hud\.js(?:\?[^"]*)?(")/;
  const found = homeBoot(html);
  if (!css.test(html) || !js.test(html) || !found) return html;
  const intro = /^<section\b[^>]*\bdata-intro="1"/.test(earlier) ? '1' : '0';
  const blade = read('home-hud.html');
  const parts = [["{{ !empty($boot['intro']) ? '1' : '0' }}", intro], ["{{ auth('admin')->user()->name }}", escape(name)],
    ["@include('admin.partials.go_hud_mark')", read('go-hud-mark.svg').trim()]];
  if (parts.some(([mark]) => !blade.includes(mark))) return html;
  let opening = blade.replace(/^\{\{--[\s\S]*?--\}\}\s*/, '').trim();
  for (const [mark, value] of parts) opening = opening.replace(mark, () => value);
  // The opening starts with its counts, as the dashboard's page does (HomeController: initial.hologram).
  const boot = found.boot;
  const saved = typeof counters === 'function' ? counters() : counters;
  boot.initial = { ...(boot.initial || {}), hologram: saved || sectionsOnly(boot.hud_links) };
  const script = `<script type="application/json" id="${found.id}-bootstrap">${scriptJson(boot)}</script>`;
  let out = html.slice(0, start) + opening + html.slice(end);
  out = out.replace(found.text, () => script);
  return out.replace(css, (all, before, after) => before + PREFIX + 'css/home-hud.css' + after)
    .replace(js, (all, before, after) => before + PREFIX + 'js/home-hud.js' + after);
}

/**
 * A dashboard page saved on this device, in the dashboard's current look. Pages saved since the dashboard got the
 * hologram release come back unchanged (the same Buffer).
 */
function currentLook(body, { origin, name = '', counters = null } = {}) {
  const html = body.toString('utf8');
  if (!html.includes('data-dashboard-actor="')) return body;
  const out = currentHome(currentShell(html, origin), name, counters);
  return out === html ? body : Buffer.from(out);
}

// The opening's own words when its counts cannot be read again (home-hud.js, stale()).
const STALE = "<script>(function(){var r=document.getElementById('go-hud');if(!r)return;var l=r.querySelector('.hud-live'),"
  + "f=r.querySelector('[data-hud-freshness]');if(l)l.classList.add('stale');if(f)f.textContent='تعذّر التحديث · آخر أرقام متاحة';})();</script>";

/**
 * The home page shown from the device's copy: its GO opening says from the start that its counts are the last ones
 * read from the dashboard (it would say "live" until its first check). Other pages come back unchanged.
 */
function fromDevice(body) {
  const html = body.toString('utf8');
  const script = /<script\b[^>]*\bsrc="[^"]*\/dashboard\/js\/home-hud\.js(?:\?[^"]*)?"[^>]*><\/script>/.exec(html);
  if (!script || !html.includes('data-hud-robot')) return body;
  const at = script.index + script[0].length;
  return Buffer.from(html.slice(0, at) + STALE + html.slice(at));
}

module.exports = { currentLook, fromDevice, hologramFile, homeBoot, sectionsOnly, PREFIX, SECTIONS };
