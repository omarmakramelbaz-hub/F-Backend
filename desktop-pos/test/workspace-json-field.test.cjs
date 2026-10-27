'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const { rawJsonField } = require('../src/workspace-json-field.cjs');

test('a catalog array preserves integral floats, 64-bit numbers and original escapes', () => {
  const array = '[{"id":9007199254740999,"amount":1.0,"label":"\\u0641\\/x","nested":{"a":[true,false,null,-1.20e+3]}}]';
  const wire = '{"tables":{"created_scopes":[0]},"created_scopes":' + array + ',"tail":0}';
  assert.equal(rawJsonField(wire, 'created_scopes'), array);
  assert.notEqual(JSON.stringify(JSON.parse(array)), array);
});

test('top-level keys may be escaped and quoted field-like text is never a key', () => {
  const wire = '{"text":"\\\"created_scopes\\\": [99]", "created_\\u0073copes" : [1, {"text":"},]\\\""}] }';
  assert.equal(rawJsonField(wire, 'created_scopes'), '[1, {"text":"},]\\\""}]');
});

test('only the requested top-level field is returned', () => {
  assert.equal(rawJsonField('{"nested":{"created_scopes":[1]}}', 'created_scopes'), null);
  assert.equal(rawJsonField(' \n {"created_scopes": []} \t', 'created_scopes'), '[]');
  assert.equal(rawJsonField('{"created_scopes":null}', 'created_scopes'), 'null');
});

for (const wire of [
  '{"created_scopes":[],"created_scopes":[]}', '{"created_scopes":[],"created_\\u0073copes":[]}',
  '{"other":1,"other":2,"created_scopes":[]}', '{"created_scopes":[]}{}', '[{"created_scopes":[]}]',
  '{"created_scopes":[1,]}', '{"created_scopes":[01]}', '{"created_scopes":[1.]}', '{"created_scopes":[1e]}',
  '{"created_scopes":[-]}', '{"created_scopes":[tru]}', '{"created_scopes":["\\x00"]}',
  '{"created_scopes":["\\u000"]}', '{"created_scopes":["unterminated]}', '{"created_scopes":[]',
  '{"created_scopes":[],}', '{"created_scopes" []}', '{"created_scopes":["a\nb"]}',
  '{"created_scopes":[{}, {"a" 1}]}', '{"created_scopes":[{}, {"a":}]}',
]) test('malformed or ambiguous raw JSON fails closed: ' + JSON.stringify(wire), () => {
  assert.equal(rawJsonField(wire, 'created_scopes'), null);
});

test('deep raw JSON fails closed without an unbounded native stack', () => {
  assert.equal(rawJsonField('{"created_scopes":' + '['.repeat(140) + '0' + ']'.repeat(140) + '}', 'created_scopes'), null);
});

test('the scanner skips a large SQL-like value without reading numbers as JavaScript values', () => {
  const rows = '[' + Array.from({ length: 10000 }, () => '{"id":18446744073709551615,"value":1.0}').join(',') + ']';
  assert.equal(rawJsonField('{"tables":' + rows + ',"created_scopes":[]}', 'created_scopes'), '[]');
});
