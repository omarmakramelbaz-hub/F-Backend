'use strict';

// The quantity of an item already in an invoice: typed quantities are read the ways people write them (".5", "0,5",
// Arabic and Persian digits), in quantity boxes only; money boxes are never touched.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public/dashboard/js/pos-line-quantity.js'), 'utf8');
const listeners = [];
const window = {};
const sandbox = { window, document: { documentElement: { lang: 'ar' }, addEventListener: (type, fn, capture) => listeners.push({ type, fn, capture: !!capture }) } };
vm.createContext(sandbox);
vm.runInContext(source, sandbox);
const { normalize } = window.GoPosLineQuantity;

for (const [typed, read] of [['.5', '0.5'], ['0,5', '0.5'], [',75', '0.75'], ['٠٫٥', '0.5'], ['۰٫۲۵', '0.25'], ['١،٥', '1.5'], [' 1.250 ', '1.250'],
    ['2.', '2'], ['3', '3'], ['', '']]) {
    assert.strictEqual(normalize(typed), read, typed);
}

// Once installed: a second run (the dashboard's page switch runs page scripts again) adds nothing.
const count = listeners.length;
vm.runInContext(source, sandbox);
assert.strictEqual(listeners.length, count);

const change = listeners.find((listener) => listener.type === 'change' && listener.capture);
const keydown = listeners.find((listener) => listener.type === 'keydown' && listener.capture);
const box = (value, quantity) => ({ value, matches: (selector) => quantity && selector.includes('[data-pos-line-quantity]') });

const line = box('0,5', true);
change.fn({ target: line });
assert.strictEqual(line.value, '0.5', 'A line\'s quantity is read before the page applies it');
const enter = box('.25', true);
keydown.fn({ key: 'Enter', target: enter });
assert.strictEqual(enter.value, '0.25', 'Enter applies it too');
const other = box('.25', true);
keydown.fn({ key: 'a', target: other });
assert.strictEqual(other.value, '.25', 'Other keys change nothing');
const money = box('1,000', false);
change.fn({ target: money });
assert.strictEqual(money.value, '1,000', 'Money boxes are never touched');

console.log('pos line quantity: ok');
