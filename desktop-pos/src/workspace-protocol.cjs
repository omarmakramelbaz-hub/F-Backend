'use strict';
const crypto = require('node:crypto');
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const exact = (value, required, optional = []) => object(value) && required.every(key => Object.hasOwn(value, key))
  && Object.keys(value).every(key => required.includes(key) || optional.includes(key));
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const integer = value => Number.isSafeInteger(value) && value > 0;
const compare = (left, right) => Buffer.compare(Buffer.from(left), Buffer.from(right));
function ordered(value) {
  if (Array.isArray(value)) return value.map(ordered);
  if (object(value)) return Object.fromEntries(Object.keys(value).sort(compare).map(key => [key, ordered(value[key])]));
  return value;
}
// Same UTF-8 key order and literal Unicode/slashes as the PHP Authority codec.
const canonical = value => JSON.stringify(ordered(value));
const digest = value => crypto.createHash('sha256').update(canonical(value)).digest('hex');
const WORKSPACE_HEADERS = Object.freeze(['Device', 'Token', 'Actor', 'Revision', 'Grant', 'Root'].map(name => 'X-Fasakhansta-Workspace-' + name));
/** Native-only authority provider; never merge renderer-supplied proof fields. */
function authorityHeaders(root, actor, token) {
  return { [WORKSPACE_HEADERS[0]]: root.workspaceId, [WORKSPACE_HEADERS[1]]: token,
    [WORKSPACE_HEADERS[2]]: String(actor.actorId), [WORKSPACE_HEADERS[3]]: String(actor.manifestRevision),
    [WORKSPACE_HEADERS[4]]: actor.grant, [WORKSPACE_HEADERS[5]]: root.rootFingerprint };
}
module.exports = { object, exact, hash, uuid, integer, compare, canonical, digest, WORKSPACE_HEADERS, authorityHeaders };
