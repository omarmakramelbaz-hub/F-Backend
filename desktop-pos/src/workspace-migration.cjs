'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const path = require('node:path');

const COUNTS = Object.freeze(['pending', 'conflicts', 'printEffects', 'legacyPending', 'legacyConflicts', 'openOrders', 'remotePending']);
const HOLDS = Object.freeze(['refresh', 'return', 'remoteDirty', 'delta', 'central']);
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const exact = (value, names) => object(value) && Object.keys(value).sort().join(',') === [...names].sort().join(',');
const id = value => value === 'default' || typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const digest = value => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const error = code => Object.assign(Error('Workspace migration inventory could not be verified.'), { code });
const check = signal => { if (signal?.aborted) throw error('WORKSPACE_MIGRATION_CANCELLED'); };
const identity = value => [value.dev, value.ino, value.mode, value.nlink, value.mtimeMs, value.ctimeMs].join(':');

function snapshot(value, profileId) {
  if (!exact(value, ['format', 'profileId', 'prepared', 'identitySha256', 'counts', 'holds'])
      || value.format !== 1 || value.profileId !== profileId || !id(profileId)
      || typeof value.prepared !== 'boolean' || !hash(value.identitySha256)
      || !exact(value.counts, COUNTS) || !exact(value.holds, HOLDS)
      || COUNTS.some(name => !Number.isSafeInteger(value.counts[name]) || value.counts[name] < 0)
      || HOLDS.some(name => typeof value.holds[name] !== 'boolean')) throw error('WORKSPACE_MIGRATION_SNAPSHOT_INVALID');
  return { format: 1, profileId, prepared: value.prepared, identitySha256: value.identitySha256,
    counts: Object.fromEntries(COUNTS.map(name => [name, value.counts[name]])),
    holds: Object.fromEntries(HOLDS.map(name => [name, value.holds[name]])) };
}

/**
 * Read-only prerequisite for conversion of every retained protocol-1 profile.
 * inspectProfile is a trusted native read boundary: it must include the legacy
 * POS ledger even when the dashboard is not prepared. Unknown state defers.
 * A clean inventory is NOT a write fence. The activation owner must acquire
 * the original database/accepted-work fence and recheck before committing.
 */
class WorkspaceMigrationInventory {
  constructor({ profiles, inspectProfile }) {
    if (typeof profiles?.list !== 'function' || typeof profiles?.account !== 'function'
        || typeof profiles.root !== 'string' || !path.isAbsolute(profiles.root) || path.resolve(profiles.root) !== profiles.root
        || typeof inspectProfile !== 'function') throw error('WORKSPACE_MIGRATION_CONFIGURATION_INVALID');
    this.profiles = profiles; this.inspectProfile = inspectProfile;
  }
  async retainedDirectories(listed, signal) {
    const root = path.join(this.profiles.root, 'accounts'), known = new Set(listed.map(row => row.id)), names = [], orphans = [];
    try {
      let cursor = path.parse(this.profiles.root).root;
      for (const component of this.profiles.root.slice(cursor.length).split(path.sep).filter(Boolean)) {
        cursor = path.join(cursor, component); const info = await fs.lstat(cursor);
        if (!info.isDirectory() || info.isSymbolicLink()) return { verified: false, digest: null, orphans: [] };
      }
      let before;
      try { before = await fs.lstat(root); }
      catch (caught) {
        if (caught.code === 'ENOENT') return { verified: listed.every(row => row.id === 'default'), digest: digest({ absent: true }), orphans: [] };
        throw caught;
      }
      if (!before.isDirectory() || before.isSymbolicLink()) return { verified: false, digest: null, orphans: [] };
      const directory = await fs.opendir(root); let verified = true;
      try {
        for await (const entry of directory) {
          check(signal);
          if (names.length >= 4096) return { verified: false, digest: null, orphans };
          const safe = entry.isDirectory() && !entry.isSymbolicLink() && entry.name !== 'default' && id(entry.name);
          names.push({ name: entry.name, safe });
          if (!safe || !known.has(entry.name)) verified = false;
          if (safe && !known.has(entry.name)) orphans.push(entry.name);
        }
      } finally { await directory.close().catch(() => {}); }
      const after = await fs.lstat(root); if (identity(before) !== identity(after)) verified = false;
      const found = new Set(names.map(row => row.name));
      if (listed.some(row => row.id !== 'default' && !found.has(row.id))) verified = false;
      return { verified, digest: digest({ identity: identity(after), names: names.sort((a, b) => a.name.localeCompare(b.name)) }), orphans: orphans.sort() };
    } catch (caught) {
      check(signal); return { verified: false, digest: null, orphans: [] };
    }
  }
  async inspectAll({ signal } = {}) {
    check(signal);
    let listed;
    try { listed = await this.profiles.list(); }
    catch { check(signal); return this.result([], false); }
    check(signal);
    if (!Array.isArray(listed) || !listed.length || listed.length > 1024
        || listed.some(row => !object(row) || !id(row.id))
        || new Set(listed.map(row => row.id)).size !== listed.length)
      return this.result([], false);
    const retained = await this.retainedDirectories(listed, signal); check(signal);
    const rows = retained.orphans.map(profileId => ({ profileId, state: 'unknown', snapshot: null }));
    for (const row of [...listed].sort((a, b) => a.id.localeCompare(b.id))) {
      check(signal);
      try {
        const account = await this.profiles.account(row.id); check(signal);
        if (!object(account) || account.id !== row.id) throw error('WORKSPACE_MIGRATION_PROFILE_INVALID');
        const value = snapshot(await this.inspectProfile(account, { signal }), row.id); check(signal);
        const dirty = COUNTS.some(name => value.counts[name] !== 0) || HOLDS.some(name => value.holds[name]);
        rows.push({ profileId: row.id, state: dirty ? 'blocked' : 'clean', snapshot: value });
      } catch (caught) {
        check(signal);
        rows.push({ profileId: row.id, state: 'unknown', snapshot: null });
      }
    }
    check(signal);
    let after;
    try { after = await this.profiles.list(); }
    catch { check(signal); return this.result(rows, false); }
    check(signal);
    const identities = list => list.map(row => row.id).sort();
    const finalRetained = await this.retainedDirectories(listed, signal); check(signal);
    const verified = retained.verified && finalRetained.verified && retained.digest === finalRetained.digest
      && Array.isArray(after) && after.length === listed.length
      && after.every(row => object(row) && id(row.id))
      && JSON.stringify(identities(after)) === JSON.stringify(identities(listed));
    return this.result(rows, verified);
  }
  result(rows, rosterVerified) {
    const copy = structuredClone(rows).sort((a, b) => a.profileId.localeCompare(b.profileId));
    return { format: 1, kind: 'go-erp-workspace-migration-inventory', rosterVerified,
      ready: rosterVerified && copy.every(row => row.state === 'clean'),
      digest: digest({ format: 1, rosterVerified, rows: copy }), rows: copy };
  }
  async unchangedAndClean(expected, { signal } = {}) {
    if (!exact(expected, ['format', 'kind', 'rosterVerified', 'ready', 'digest', 'rows'])
        || expected.format !== 1 || expected.kind !== 'go-erp-workspace-migration-inventory'
        || expected.rosterVerified !== true || expected.ready !== true || !hash(expected.digest)
        || !Array.isArray(expected.rows) || !expected.rows.length
        || expected.rows.some(row => !exact(row, ['profileId', 'state', 'snapshot']) || row.state !== 'clean')
        || expected.digest !== digest({ format: 1, rosterVerified: true, rows: expected.rows })) return false;
    const current = await this.inspectAll({ signal });
    return current.ready && current.digest === expected.digest;
  }
  static publicState(inventory) {
    if (!object(inventory) || !Array.isArray(inventory.rows))
      return { ready: false, profiles: 0, blocked: 0, unknown: true };
    return { ready: inventory.ready === true && inventory.rosterVerified === true && inventory.rows.length > 0
        && inventory.rows.every(row => row.state === 'clean'), profiles: inventory.rows.length,
      blocked: inventory.rows.filter(row => row.state !== 'clean').length,
      unknown: inventory.rosterVerified !== true || inventory.rows.some(row => row.state === 'unknown') };
  }
}

module.exports = { WorkspaceMigrationInventory, snapshot, COUNTS, HOLDS };
