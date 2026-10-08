'use strict';
const DEFAULT_ORIGIN = 'https://fasakhaninja.com';
function sameOrigin(value, origin = DEFAULT_ORIGIN) {
  try { const u = new URL(value); return u.origin === origin && !u.username && !u.password; }
  catch { return false; }
}
function externalURL(value) {
  try { const u = new URL(value); return ['https:', 'http:'].includes(u.protocol) && !u.username && !u.password; }
  catch { return false; }
}
function networkFailure(error) {
  return /ERR_(?:INTERNET_DISCONNECTED|NAME_NOT_RESOLVED|CONNECTION_(?:REFUSED|RESET|CLOSED|TIMED_OUT)|NETWORK_CHANGED|ADDRESS_UNREACHABLE|TIMED_OUT)/.test(String(error));
}
module.exports = { DEFAULT_ORIGIN, sameOrigin, externalURL, networkFailure };
