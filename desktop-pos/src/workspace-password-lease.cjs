'use strict';

const { performance } = require('node:perf_hooks');
const { originalSignin } = require('./account-login.cjs');

const roles = new Set(['admin', 'vendor', 'resturant_owner']);
const invalid = () => Object.assign(Error('تعذر تأكيد بيانات جلسة الدخول المؤقتة.'), { code: 'WORKSPACE_PASSWORD_LEASE_INVALID' });
const unavailable = () => Object.assign(Error('أعد إدخال كلمة المرور لتأكيد الدخول المحلي.'), { code: 'WORKSPACE_PASSWORD_LEASE_UNAVAILABLE' });

function serverOptions(input) {
  if (!input || Object.getPrototypeOf(input) !== Object.prototype) throw invalid();
  const fields = Object.getOwnPropertyDescriptors(input);
  if (Reflect.ownKeys(fields).some(key => typeof key !== 'string'
    || !['browser', 'origin', 'email', 'password', 'remember', 'signal'].includes(key)
    || !Object.hasOwn(fields[key], 'value'))) throw invalid();
  const { browser, origin, email, password, remember, signal } = input;
  if (typeof browser?.fetch !== 'function' || typeof origin !== 'string'
    || typeof email !== 'string' || !email.trim() || email.length > 320 || /[\x00-\x1f\x7f]/.test(email)
    || typeof password !== 'string' || !password || password.length > 4096 || typeof remember !== 'boolean'
    || signal !== undefined && !(signal instanceof AbortSignal)) throw invalid();
  let url;
  try { url = new URL(origin); } catch { throw invalid(); }
  if (url.protocol !== 'https:' || url.origin !== origin || url.username || url.password) throw invalid();
  return { browser, origin, email: email.trim(), password, remember, signal };
}

/** A password may cross the native server/local bridge once; it never becomes an actor credential on disk. */
function leaseFor(password, signal, ttlMs, now) {
  const started = now();
  if (!Number.isFinite(started) || signal?.aborted) throw unavailable();
  const secret = Buffer.from(password, 'utf8');
  let available = true, last = started, timer;
  const dispose = () => {
    available = false;
    secret.fill(0);
    clearTimeout(timer);
    signal?.removeEventListener('abort', dispose);
  };
  const valid = () => {
    if (!available) return false;
    let current;
    try { current = now(); } catch { dispose(); return false; }
    if (!Number.isFinite(current) || current < last || current - started >= ttlMs || signal?.aborted) {
      dispose(); return false;
    }
    last = current;
    return true;
  };
  timer = setTimeout(dispose, ttlMs);
  timer.unref();
  signal?.addEventListener('abort', dispose, { once: true });
  return Object.freeze({
    valid,
    dispose,
    async consume(consumer) {
      if (typeof consumer !== 'function') { dispose(); throw invalid(); }
      if (!valid()) throw unavailable();
      let value = secret.toString('utf8');
      // Invalidate before invoking any asynchronous code, including a throwing
      // or reentrant consumer. The remaining string lives only for that call.
      dispose();
      try { return await consumer(value); }
      finally { value = ''; }
    }
  });
}

async function authenticateServerWithLease(input, { ttlMs = 120000, now = () => performance.now() } = {}) {
  if (!Number.isSafeInteger(ttlMs) || ttlMs < 1 || ttlMs > 120000 || typeof now !== 'function') throw invalid();
  const options = serverOptions(input);
  try {
    if (options.signal?.aborted) throw unavailable();
    // The issuer is the original password POST and protected identity document,
    // not a callback, alias lookup, cached grant, or caller-provided actor.
    const actor = await originalSignin(options);
    if (options.signal?.aborted || !Number.isSafeInteger(actor?.actorId) || actor.actorId < 1 || !roles.has(actor.role)) throw unavailable();
    return Object.freeze({ actor: Object.freeze({ actorId: actor.actorId, role: actor.role }),
      lease: leaseFor(options.password, options.signal, ttlMs, now) });
  } finally { options.password = ''; }
}

module.exports = { authenticateServerWithLease };
