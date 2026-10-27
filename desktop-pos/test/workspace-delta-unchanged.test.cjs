'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const { unchanged, validIndex, schemaHash } = require('../src/workspace-delta-unchanged.cjs');
const prepared = require('../src/workspace-prepared.cjs');
const { hash } = require('./helpers/workspace-fixture.cjs');
const { canonical } = require('../src/workspace-profile.cjs');
const codec = require('../../tests/fixtures/desktop-workspace-delta-codec-v2.json');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const tableHash = (scope, name, schema, rowset, count) => sha('desktop-workspace-table-v2\0'
  + canonical({ ...scope, table: name, schema_sha256: schema, rowset_sha256: rowset, count }));
const scopeFor = m => ({ workspace_id: m.workspace_id, revision: m.revision,
  root_fingerprint: m.root_fingerprint, roster_sha256: m.roster_sha256 });

const { fixture } = require('./helpers/workspace-unchanged-fixture.cjs');
const { catalogFixture } = require('./helpers/workspace-unchanged-fixture.cjs');

const evaluate = f => unchanged(f.capture, f.delta, f.previous, f.context);

test('a private catalog receipt permits nonempty unchanged scopes using exact retained PHP bytes', async () => {
  const f = await catalogFixture();
  assert.equal(evaluate(f), true);
  const download = require('../src/dashboard-download.cjs'), { rawJsonField } = require('../src/workspace-json-field.cjs');
  const raw = rawJsonField(download.input(f.delta), 'created_scopes');
  assert.match(raw, /9007199254740999/); assert.match(raw, /1\.0/);
  assert.notEqual(JSON.stringify(f.delta.created_scopes), raw);
  assert.equal(sha(raw), f.capture.catalog.sha256);
  assert.equal(evaluate({ ...f, delta: structuredClone(f.delta) }), false, 'cloning loses exact PHP wire authority');
});

for (const [label, mutate] of Object.entries({
  'missing private capture catalog': f => { delete f.capture.catalog; },
  'extra private catalog field': f => { f.capture.catalog.actor_id = 202; },
  'foreign physical root': f => { f.capture.catalog.root_fingerprint = hash('other root'); },
  'foreign workspace': f => { f.capture.catalog.workspace_id = '55555555-5555-4555-8555-555555555555'; },
  'foreign source snapshot': f => { f.capture.catalog.snapshot_id = f.delta.snapshot_id; },
  'foreign source schema': f => { f.capture.catalog.schema_hash = hash('other schema'); },
  'new local journal sequence': f => { f.capture.catalog.sequence++; },
  'incorrect catalog count': f => { f.capture.catalog.count++; },
  'incorrect catalog commitment': f => { f.capture.catalog.sha256 = hash('other raw grants'); },
  'negative count': f => { f.capture.catalog.count = -1; },
  'wrong protocol': f => { f.capture.catalog.format = 1; },
})) test('nonempty catalog fallback rejects ' + label, async () => {
  const f = await catalogFixture(); mutate(f); assert.equal(evaluate(f), false);
});

test('the explicit private catalog receipt also supports the empty PHP list', async () => {
  const f = await catalogFixture('[]'); assert.equal(evaluate(f), true);
});

test('the native unchanged index validator agrees with the PHP v2 codec fixture', () => {
  assert.equal(validIndex('users', codec.users_index, scopeFor(codec.manifest)), true);
  assert.equal(schemaHash(codec.users_ddl, 'users'), codec.users_index.schema_sha256);
  assert.equal(tableHash(scopeFor(codec.manifest), 'users', codec.users_index.schema_sha256,
    codec.users_index.rowset_sha256, codec.users_index.count), codec.users_index.table_sha256);
  const digests = Object.keys(codec.users_index.hashes).sort();
  assert.equal(sha('dashboard-delta-table-v1\0' + digests.map(key => key + ':' + codec.users_index.hashes[key] + '\n').join('')),
    codec.users_index.rowset_sha256);
});

test('fully unchanged server data keeps the physical DB despite a fresh remote snapshot id', () => {
  const f = fixture(), before = structuredClone(f);
  assert.notEqual(f.delta.snapshot_id, f.previous.candidate.snapshotId);
  assert.equal(evaluate(f), true); assert.deepEqual(f, before);
});

test('empty PHP maps and object maps both represent a genuinely empty removal set', () => {
  const f = fixture(); f.delta.tables.users.delta.remove = {};
  assert.equal(evaluate(f), true);
});

test('empty table indexes support the PHP empty hashes array without inventing row values', () => {
  const f = fixture(), scope = scopeFor(f.previous.manifest), ddl = 'CREATE TABLE `orders` (\n  `id` bigint unsigned NOT NULL\n) ENGINE=InnoDB';
  const schema = schemaHash(ddl, 'orders'), rowset = sha('dashboard-delta-table-v1\0');
  const before = { format: 2, kind: 'desktop-workspace-table-index', ...scope, table: 'orders', schema_sha256: schema,
    count: 0, hashes: [], rowset_sha256: rowset, table_sha256: tableHash(scope, 'orders', schema, rowset, 0) };
  f.capture.index.tables.orders = before;
  f.previous.candidate.receipt.tables++;
  f.previous.candidate.receipt.table_rows.orders = 0;
  f.previous.candidate.prepared = prepared.create({ credential: f.previous.credential, manifest: f.previous.manifest,
    candidate: { ...f.previous.candidate, mediaVerified: true }, refreshId: f.previous.candidate.prepared.refreshId });
  f.delta.tables.orders = { ddl, delta: { format: 2, kind: 'desktop-workspace-table-delta', ...scope, table: 'orders',
    before_sha256: before.table_sha256, schema_sha256: schema, remove: {}, add: [], rowset_sha256: rowset,
    target_hash: before.table_sha256, count: 0 } };
  assert.equal(evaluate(f), true);
  f.capture.index.tables.orders.hashes = {}; assert.equal(evaluate(f), true);
});

test('DDL identity ignores only the mutable table counter, preserving quoted content', () => {
  const base = codec.users_ddl + " COMMENT='literal AUTO_INCREMENT=987'";
  const withCounter = base.replace(' ENGINE=InnoDB', ' ENGINE=InnoDB AUTO_INCREMENT=123456');
  assert.equal(schemaHash(base, 'users'), schemaHash(withCounter, 'users'));
  assert.notEqual(schemaHash(base, 'users'), schemaHash(base.replace('987', '988'), 'users'));
  assert.equal(schemaHash(base.replace(/\n/g, '\r\n'), 'users'), schemaHash(base, 'users'));
  const f = fixture(); f.delta.tables.users.ddl += ' AUTO_INCREMENT=100'; assert.equal(evaluate(f), true);
});

const changes = {
  'v1 capture': f => { f.capture.format = 1; },
  'extra capture field': f => { f.capture.actor = 202; },
  'different capture snapshot': f => { f.capture.identity.snapshot_id = f.delta.snapshot_id; },
  'custody replaced by logged-in B': f => { f.capture.identity.actor_id = 202; },
  'foreign physical source database': f => { f.capture.source_database = f.capture.capture_database; },
  'capture source revision changed': f => { f.context.manifest.sourceRevision = 'c'.repeat(40); },
  'capture source fingerprint changed': f => { f.context.sourceFingerprint = { ...f.context.sourceFingerprint, sha256: hash('other code') }; },
  'publication source changed': f => { f.context.workspaceApproval.sourceRevision = 'c'.repeat(40); },
  'new publication sequence needs a real verified transition': f => { f.context.workspaceApproval.sequence++; },
  'capture global sequence mismatch': f => { f.capture.replay.sequence++; },
  'capture foreign replay root': f => { f.capture.replay.root_fingerprint = hash('foreign root'); },
  'capture coverage changed': f => { f.capture.identity.coverage.print_protocol = 2; },
  'server changed workspace membership revision': f => { f.delta.workspace_manifest.revision++; },
  'server changed actor permission': f => { f.delta.workspace_manifest.members[0].permissions.names.push('wallet-list'); },
  'server changed manifest signature': f => { f.delta.workspace_manifest.signature = hash('other signed manifest'); },
  'server changed immutable custody': f => { f.delta.workspace_enrollment.custodian_actor_id = 202; },
  'server changed root nonce': f => { f.delta.workspace_enrollment.enrollment_hash = hash('other enrollment'); },
  'server changed enrolled-at': f => { f.delta.workspace_enrollment.enrolled_at = '2026-10-28 00:00:00'; },
  'server changed root token': f => { f.delta.workspace_enrollment.token_hash = hash('different root token'); },
  'server extra enrollment field': f => { f.delta.workspace_enrollment.actor_id = 202; },
  'server legacy enrollment disagrees': f => { f.delta.enrollment.enrollment_hash = hash('other enrollment'); },
  'server schema changed': f => { f.delta.schema_hash = hash('schema transition'); },
  'server branch union changed': f => { f.delta.branches.push('f:999'); },
  'server coverage changed': f => { f.delta.coverage.catalog_scopes = 1; },
  'server has media errors': f => { f.delta.media_issues.push({ reason: 'missing' }); },
  'server created catalog needs separate verified import': f => { f.delta.created_scopes.push({ branch: 'gs:999' }); },
  'server changed summary': f => { f.delta.delta_summary.changed_tables = 1; },
  'server unknown summary field': f => { f.delta.delta_summary.ignored = 0; },
  'server summary scalar type': f => { f.delta.delta_summary.changed_tables = '0'; },
  'extra top-level v2 field': f => { f.delta.unknown = false; },
  'server removed a table despite zero summary': f => { delete f.delta.tables.users; },
  'server added a table despite zero summary': f => { f.delta.tables.orders = structuredClone(f.delta.tables.users); },
  'server table retired since original receipt': f => { f.previous.candidate.receipt.table_rows.orders = 0; },
  'extra per-table envelope field': f => { f.delta.tables.users.unknown = true; },
  'DDL changes column shape': f => { f.delta.tables.users.ddl = f.delta.tables.users.ddl.replace('varchar(100)', 'varchar(200)'); },
  'DDL renamed table': f => { f.delta.tables.users.ddl = f.delta.tables.users.ddl.replace('`users`', '`wallets`'); },
  'v1 table delta': f => { f.delta.tables.users.delta.format = 1; },
  'unexpected replace marker even when false': f => { f.delta.tables.users.delta.replace = false; },
  'server changed before hash': f => { f.delta.tables.users.delta.before_sha256 = hash('foreign table'); },
  'server changed target hash': f => { f.delta.tables.users.delta.target_hash = hash('changed table'); },
  'server changed target rowset': f => { f.delta.tables.users.delta.rowset_sha256 = hash('changed rows'); },
  'server table delta in different member context': f => { f.delta.tables.users.delta.revision++; },
  'server removed one row despite zero summary': f => { f.delta.tables.users.delta.remove = { [Object.keys(f.capture.index.tables.users.hashes)[0]]: 1 }; },
  'server added one row despite zero summary': f => { f.delta.tables.users.delta.add = [{ id: '9007199254740999', balance: '1.0' }]; },
  'captured row multiplicity invalid': f => { f.capture.index.tables.users.hashes[Object.keys(f.capture.index.tables.users.hashes)[0]] = 0; },
  'captured rowset forged': f => { f.capture.index.tables.users.rowset_sha256 = hash('forged rowset'); },
  'captured table hash forged': f => { f.capture.index.tables.users.table_sha256 = hash('forged table'); },
  'captured table count over 500k': f => { f.capture.index.tables.users.count = 500001; },
  'captured table context changed': f => { f.capture.index.tables.users.root_fingerprint = hash('foreign root'); },
};
for (const [name, mutate] of Object.entries(changes)) test(name + ' cannot take the unchanged shortcut', () => {
  const f = fixture(); mutate(f); assert.equal(evaluate(f), false);
});

test('new media tickets may rotate while the verified file receipt stays identical', () => {
  const f = fixture(), file = { path: 'products/one.png', sha256: hash('image'), bytes: 11, mime: 'image/png' };
  f.previous.candidate.receipt.media = [file]; f.capture.identity.media = [structuredClone(file)];
  f.previous.candidate.prepared = prepared.create({ credential: f.previous.credential, manifest: f.previous.manifest,
    candidate: { ...f.previous.candidate, mediaVerified: true }, refreshId: f.previous.candidate.prepared.refreshId });
  f.delta.media = [{ ...file, ticket: 'new signed download ticket' }];
  assert.equal(evaluate(f), true);
  f.delta.media[0].sha256 = hash('changed image'); assert.equal(evaluate(f), false);
});

test('changed SQL rows are never canonicalized or visited by the unchanged optimization', () => {
  const f = fixture(), row = {};
  Object.defineProperty(row, 'unsafe_integer', { enumerable: true, get() { throw Error('SQL row was accessed'); } });
  f.delta.tables.users.delta.add = [row];
  assert.equal(evaluate(f), false);
});

test('malformed untrusted input returns false without throwing or changing state', () => {
  for (const value of [null, undefined, {}, [], 1, 'wrong']) {
    const f = fixture(); assert.equal(unchanged(value, f.delta, f.previous, f.context), false);
    assert.equal(unchanged(f.capture, value, f.previous, f.context), false);
    assert.equal(unchanged(f.capture, f.delta, value, f.context), false);
    assert.equal(unchanged(f.capture, f.delta, f.previous, value), false);
  }
});
