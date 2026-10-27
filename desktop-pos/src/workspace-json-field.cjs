'use strict';

// Find the original bytes of one top-level JSON value. Numbers are scanned as
// lexical tokens, so PHP integral floats and 64-bit identifiers keep their
// exact wire representation. This helper never constructs SQL row values.
function rawJsonField(input, field) {
  if (typeof input !== 'string' || typeof field !== 'string' || !field || Buffer.byteLength(input) > 256 * 1024 ** 2) return null;
  let cursor = 0, result = null;
  const invalid = () => { throw Error('Invalid JSON field frame'); };
  const digit = value => value >= 48 && value <= 57;
  const whitespace = () => {
    while (cursor < input.length) {
      const value = input.charCodeAt(cursor);
      if (value !== 32 && value !== 9 && value !== 10 && value !== 13) return;
      cursor++;
    }
  };
  function string() {
    if (input[cursor++] !== '"') invalid();
    while (cursor < input.length) {
      const value = input.charCodeAt(cursor++);
      if (value === 34) return;
      if (value < 32) invalid();
      if (value !== 92) continue;
      const escaped = input[cursor++];
      if (escaped === 'u') {
        if (!/^[a-fA-F0-9]{4}$/.test(input.slice(cursor, cursor + 4))) invalid();
        cursor += 4;
      } else if (!escaped || !'"\\/bfnrt'.includes(escaped)) invalid();
    }
    invalid();
  }
  function number() {
    if (input[cursor] === '-') cursor++;
    if (input[cursor] === '0') cursor++;
    else {
      if (input.charCodeAt(cursor) < 49 || input.charCodeAt(cursor) > 57 || cursor >= input.length) invalid();
      while (digit(input.charCodeAt(cursor))) cursor++;
    }
    if (input[cursor] === '.') {
      cursor++; if (!digit(input.charCodeAt(cursor))) invalid();
      while (digit(input.charCodeAt(cursor))) cursor++;
    }
    if (input[cursor] === 'e' || input[cursor] === 'E') {
      cursor++; if (input[cursor] === '+' || input[cursor] === '-') cursor++;
      if (!digit(input.charCodeAt(cursor))) invalid();
      while (digit(input.charCodeAt(cursor))) cursor++;
    }
  }
  function value(depth) {
    if (depth > 128) invalid();
    whitespace();
    if (input[cursor] === '"') return string();
    if (input[cursor] === '{') return object(depth + 1, false);
    if (input[cursor] === '[') {
      cursor++; whitespace(); if (input[cursor] === ']') { cursor++; return; }
      while (true) {
        value(depth + 1); whitespace(); const separator = input[cursor++];
        if (separator === ']') return; if (separator !== ',') invalid();
      }
    }
    for (const literal of ['true', 'false', 'null']) {
      if (input.slice(cursor, cursor + literal.length) === literal) { cursor += literal.length; return; }
    }
    number();
  }
  function object(depth, root) {
    if (depth > 128 || input[cursor++] !== '{') invalid();
    const names = root ? new Set() : null;
    whitespace(); if (input[cursor] === '}') { cursor++; return; }
    while (true) {
      whitespace(); const start = cursor; string();
      let name;
      if (root) {
        name = JSON.parse(input.slice(start, cursor));
        if (names.has(name)) invalid(); names.add(name);
      }
      whitespace(); if (input[cursor++] !== ':') invalid(); whitespace();
      const from = cursor; value(depth + 1); const end = cursor;
      if (root && name === field) result = input.slice(from, end);
      whitespace(); const separator = input[cursor++];
      if (separator === '}') return; if (separator !== ',') invalid();
    }
  }
  try {
    whitespace(); object(0, true); whitespace();
    return cursor === input.length ? result : null;
  } catch { return null; }
}

module.exports = { rawJsonField };
