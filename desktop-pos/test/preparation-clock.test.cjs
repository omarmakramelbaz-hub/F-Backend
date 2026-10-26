const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../src/preparation-clock.js'), 'utf8');
function fixture() {
  let elapsed = 0, state = {}, listener, next = null, statusCalls = 0;
  const intervals = new Set(), documentEvents = new Map(), windowEvents = new Map();
  const controls = { nodes: [], append(node) { this.nodes.push(node); } };
  const root = { isConnected: true, querySelector(selector) {
    return selector === '[data-desktop-controls]' ? controls : controls.nodes.find(node => node.attributes['data-go-erp-preparation-time'] !== undefined);
  } };
  const document = { querySelector: () => next, createElement: () => ({ isConnected: true, attributes: {}, hidden: false,
    setAttribute(name, value) { this.attributes[name] = value; } }),
    addEventListener(name, callback) { documentEvents.set(name, callback); } };
  const window = { FasakhanstaDesktop: { status: async () => { statusCalls++; return state; }, onState(callback) { listener = callback; } },
    addEventListener(name, callback) { windowEvents.set(name, callback); } };
  window.top = window;
  const context = { window, document, performance: { now: () => elapsed }, Promise, Number, Math,
    setInterval(callback) { intervals.add(callback); return callback; }, clearInterval(callback) { intervals.delete(callback); } };
  vm.runInNewContext(script, context);
  return { controls, intervals, window, context, mount() { next = root; documentEvents.get('dashboard:page-mounted')(); },
    update(value) { listener(value); }, at(value) { elapsed = value; for (const draw of intervals) draw(); },
    leave() { documentEvents.get('dashboard:before-unload')(); }, calls: () => statusCalls,
    output: () => controls.nodes[0] };
}
test('setup timer displays elapsed time and real stage/progress, then stops at actual completion', async () => {
  const f = fixture(); f.mount(); await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.output().hidden, true);
  f.update({ phase: 'downloading', progress: 15, setup_timing: { elapsed_ms: 61000, running: true } });
  assert.match(f.output().textContent, /01:01.*تنزيل بيانات الحساب.*15٪/);
  f.at(3000); assert.match(f.output().textContent, /01:04/);
  f.update({ phase: 'ready', progress: 100, setup_timing: { elapsed_ms: 65432, running: false } });
  assert.equal(f.output().textContent, 'اكتمل الإعداد خلال 01:05');
  f.at(99000); assert.equal(f.output().textContent, 'اكتمل الإعداد خلال 01:05');
  assert.equal(f.calls(), 1, 'Drawing seconds never polls the native database.');
});
test('setup timer mounts once, ends its interval on SPA departure, and never interprets errors as HTML', async () => {
  const f = fixture(); f.mount(); await new Promise(resolve => setImmediate(resolve));
  vm.runInNewContext(script, f.context); f.mount();
  assert.equal(f.controls.nodes.length, 1); assert.equal(f.intervals.size, 1);
  f.update({ phase: '<img src=x>', progress: 5000, setup_timing: { elapsed_ms: 3601000, running: true } });
  assert.match(f.output().textContent, /01:00:01.*100٪/); assert.equal(f.output().textContent.includes('<img'), false);
  f.update({ phase: 'failed', setup_timing: { elapsed_ms: 62000, running: false } });
  assert.equal(f.output().textContent, 'مدة محاولة الإعداد: 01:02');
  f.update({ setup_timing: { elapsed_ms: Infinity, running: true } }); assert.equal(f.output().hidden, true);
  f.leave(); assert.equal(f.intervals.size, 0);
});
