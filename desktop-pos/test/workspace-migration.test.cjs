'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), path = require('node:path'), os = require('node:os'), crypto = require('node:crypto');
const AccountProfiles = require('../src/account-profiles.cjs');
const { WorkspaceMigrationInventory, COUNTS, HOLDS } = require('../src/workspace-migration.cjs');
function encryption() {
  const key = crypto.randomBytes(32);
  return { isEncryptionAvailable: () => true,
    encryptString(value) { const iv = crypto.randomBytes(12), c = crypto.createCipheriv('aes-256-gcm', key, iv), body = Buffer.concat([c.update(value), c.final()]); return Buffer.concat([iv, c.getAuthTag(), body]); },
    decryptString(raw) { const c = crypto.createDecipheriv('aes-256-gcm', key, raw.subarray(0, 12)); c.setAuthTag(raw.subarray(12, 28)); return Buffer.concat([c.update(raw.subarray(28)), c.final()]).toString('utf8'); } };
}
function state(prepared = true) { return { prepared, counts: Object.fromEntries(COUNTS.map(key => [key, 0])), holds: Object.fromEntries(HOLDS.map(key => [key, false])) }; }
async function fixture(t) {
  const temporary = await fs.realpath(os.tmpdir());
  const root = await fs.mkdtemp(path.join(temporary, 'workspace-migration-')), profiles = new AccountProfiles({ root, safeStorage: encryption() });
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  assert.equal(await fs.realpath(root), root, 'The fixture uses the canonical Windows path, not the 8.3 temporary-directory alias.');
  await profiles.bind('default', { actorId: 101, serverOrigin: 'https://dashboard.example.test' });
  const b = await profiles.create(); await profiles.bind(b.id, { actorId: 202, serverOrigin: 'https://dashboard.example.test' });
  const a = await profiles.account('default');
  for (const row of [a, b]) await fs.writeFile(path.join(row.profile, 'legacy-proof.json'), JSON.stringify(state()));
  const visited = [];
  const inspectProfile = async row => { visited.push(row.id); const raw = await fs.readFile(path.join(row.profile, 'legacy-proof.json'));
    return { format: 1, profileId: row.id, identitySha256: crypto.createHash('sha256').update(raw).digest('hex'), ...JSON.parse(raw) }; };
  return { root, profiles, a, b, visited, inspectProfile, inventory: new WorkspaceMigrationInventory({ profiles, inspectProfile }) };
}

test('a clean selected A cannot hide B pending commands or uncertain prints', async t => {
  const f = await fixture(t), blocked = state(); blocked.counts.pending = 1; blocked.counts.printEffects = 1;
  const proof = path.join(f.b.profile, 'legacy-proof.json'); await fs.writeFile(proof, JSON.stringify(blocked)); const accepted = await fs.readFile(proof);
  const result = await f.inventory.inspectAll(); assert.equal(result.ready, false); assert.equal(result.rows.length, 2);
  assert.deepEqual(new Set(f.visited), new Set(['default', f.b.id])); assert.equal((await f.profiles.selected()).id, 'default');
  assert.deepEqual(await fs.readFile(proof), accepted);
  assert.deepEqual(WorkspaceMigrationInventory.publicState(result), { ready: false, profiles: 2, blocked: 1, unknown: false });
});

test('all clean profiles are rechecked and later writes, holds or new profiles invalidate an earlier inventory', async t => {
  const f = await fixture(t), ticket = await f.inventory.inspectAll(); assert.equal(ticket.ready, true);
  assert.equal(await f.inventory.unchangedAndClean(ticket), true);
  const changed = state(); changed.holds.remoteDirty = true;
  await fs.writeFile(path.join(f.b.profile, 'legacy-proof.json'), JSON.stringify(changed));
  assert.equal(await f.inventory.unchangedAndClean(ticket), false);
  await fs.writeFile(path.join(f.b.profile, 'legacy-proof.json'), JSON.stringify(state()));
  const clean = await f.inventory.inspectAll(); const extra = await f.profiles.create();
  await fs.writeFile(path.join(extra.profile, 'legacy-proof.json'), JSON.stringify(state(false)));
  assert.equal(await f.inventory.unchangedAndClean(clean), false); assert.equal((await f.inventory.inspectAll()).rows.length, 3);
});

test('an unprepared dashboard still blocks migration for retained legacy POS work', async t => {
  const f = await fixture(t), old = state(false); old.counts.legacyPending = 1; old.counts.openOrders = 2;
  await fs.writeFile(path.join(f.a.profile, 'legacy-proof.json'), JSON.stringify(old));
  const result = await f.inventory.inspectAll(); assert.equal(result.ready, false);
  assert.equal(result.rows.find(row => row.profileId === 'default').state, 'blocked');
});

for (const field of [...COUNTS, ...HOLDS]) test('migration defers for ' + field + ' in any retained profile', async t => {
  const f = await fixture(t), blocked = state(); if (COUNTS.includes(field)) blocked.counts[field] = 1; else blocked.holds[field] = true;
  await fs.writeFile(path.join(f.b.profile, 'legacy-proof.json'), JSON.stringify(blocked));
  assert.equal((await f.inventory.inspectAll()).ready, false);
});

test('missing, malformed and unreadable evidence remains unknown rather than becoming zero', async t => {
  const f = await fixture(t); await fs.unlink(path.join(f.b.profile, 'legacy-proof.json'));
  const unknown = await f.inventory.inspectAll(); assert.equal(unknown.ready, false);
  assert.equal(WorkspaceMigrationInventory.publicState(unknown).unknown, true);
  const bad = state(); bad.counts.pending = -1; await fs.writeFile(path.join(f.b.profile, 'legacy-proof.json'), JSON.stringify(bad));
  assert.equal((await f.inventory.inspectAll()).rows.find(row => row.profileId === f.b.id).state, 'unknown');
  const rosterFailure = new WorkspaceMigrationInventory({ profiles: { root: f.root, async list() { throw Error('private path'); }, async account() {} }, inspectProfile: f.inspectProfile });
  assert.deepEqual(WorkspaceMigrationInventory.publicState(await rosterFailure.inspectAll()), { ready: false, profiles: 0, blocked: 0, unknown: true });
});

test('an unregistered retained account database blocks migration without adoption or deletion', async t => {
  const f = await fixture(t), orphan = crypto.randomUUID(), folder = path.join(f.root, 'accounts', orphan, 'dashboard', 'database');
  await fs.mkdir(folder, { recursive: true }); const sentinel = path.join(folder, 'pending-journal.ibd');
  await fs.writeFile(sentinel, 'accepted original unknown journal bytes');
  const before = await fs.readFile(path.join(f.root, 'account-profiles.enc'));
  const result = await f.inventory.inspectAll(); assert.equal(result.ready, false); assert.equal(result.rosterVerified, false);
  assert.equal(result.rows.find(row => row.profileId === orphan).state, 'unknown');
  assert.equal(f.visited.includes(orphan), false, 'An unregistered directory is never adopted or opened as another account.');
  assert.equal(await fs.readFile(sentinel, 'utf8'), 'accepted original unknown journal bytes');
  assert.deepEqual(await fs.readFile(path.join(f.root, 'account-profiles.enc')), before);
});

test('missing account registry cannot hide an orphaned retained protocol-1 database', async t => {
  const f = await fixture(t); await fs.unlink(path.join(f.root, 'account-profiles.enc'));
  const result = await f.inventory.inspectAll(); assert.equal(result.ready, false);
  assert.equal(result.rows.some(row => row.profileId === f.b.id && row.state === 'unknown'), true);
});

test('a profile added during inspection invalidates the roster instead of reporting a complete inventory', async t => {
  const f = await fixture(t); let added = false;
  const inventory = new WorkspaceMigrationInventory({ profiles: f.profiles, inspectProfile: async row => {
    const value = await f.inspectProfile(row); if (!added) { added = true; await f.profiles.create(); } return value;
  } });
  const value = await inventory.inspectAll(); assert.equal(value.ready, false); assert.equal(value.rosterVerified, false);
  assert.equal(WorkspaceMigrationInventory.publicState(value).unknown, true);
});

test('cancellation drains the current read but never presents a partial inventory as ready', async t => {
  const f = await fixture(t), controller = new AbortController();
  const inventory = new WorkspaceMigrationInventory({ profiles: f.profiles, inspectProfile: async row => { const value = await f.inspectProfile(row); controller.abort(); return value; } });
  await assert.rejects(inventory.inspectAll({ signal: controller.signal }), { code: 'WORKSPACE_MIGRATION_CANCELLED' });
  assert.equal((await f.profiles.selected()).id, 'default');
});
