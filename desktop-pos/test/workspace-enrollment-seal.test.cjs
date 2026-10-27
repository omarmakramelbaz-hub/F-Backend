'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), crypto = require('node:crypto'), path = require('node:path');
const { spawnSync } = require('node:child_process');
const { createRecipient, decryptEnrollment, parseEnvelope, MAX_ENVELOPE_BYTES, MAX_PLAINTEXT_BYTES } = require('../src/workspace-enrollment-seal.cjs');
const { canonical, digest } = require('../src/workspace-profile.cjs');
const fixture = require('./fixtures/workspace-members-v2.json');
const workspaceId = fixture.manifest.workspace_id, nonce = 'b'.repeat(64), serverOrigin = 'https://dashboard.example.test';
const php = process.env.WORKSPACE_TEST_PHP || 'php';
const phpAvailable = spawnSync(php, ['-n', '-r', 'exit(extension_loaded("openssl")?0:1);'], { encoding: 'utf8', timeout: 10000 }).status === 0;
const privatePayload = () => ({ manifest: structuredClone(fixture.manifest), credential: { workspaceId, serverOrigin,
  token: 'c'.repeat(64), enrollmentId: fixture.manifest.root_fingerprint } });
const recipientPromise = createRecipient(), secondRecipientPromise = createRecipient();
const rejected = error => error?.code === 'WORKSPACE_ENROLLMENT_SEAL_INVALID'
  && error.message === 'The private workspace enrollment could not be verified.' && error.cause === undefined;
function seal(recipient, payload = privatePayload(), overrides = {}) {
  const request = { payload, public_key: recipient.publicKey, workspace_id: workspaceId, nonce, ...overrides };
  return spawnSync(php, ['-n', path.join(__dirname, '../../tests/desktop_workspace_enrollment_seal_fixture.php')],
    { input: JSON.stringify(request), encoding: 'utf8', timeout: 15000, maxBuffer: MAX_ENVELOPE_BYTES + 1024 });
}
const options = recipient => ({ recipient, workspaceId, nonce, serverOrigin });
function sealed(recipient, value = privatePayload()) {
  const result = seal(recipient, value); assert.equal(result.status, 0, result.stderr); assert.equal(result.stderr, ''); return result.stdout;
}
function syntheticSeal(recipient, raw) {
  const header = { format: 2, kind: 'desktop-dashboard-workspace-enrollment-seal', workspace_id: workspaceId,
    recipient_sha256: recipient.recipientSha256, nonce_sha256: crypto.createHash('sha256').update(nonce).digest('hex') };
  const key = crypto.randomBytes(32), iv = crypto.randomBytes(12), cipher = crypto.createCipheriv('aes-256-gcm', key, iv, { authTagLength: 16 });
  cipher.setAAD(Buffer.from(canonical(header)));
  const ciphertext = Buffer.concat([cipher.update(raw), cipher.final()]);
  const wrapped = crypto.publicEncrypt({ key: recipient.publicKey, padding: crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha1' }, key);
  key.fill(0);
  return { header, encrypted_key: wrapped.toString('base64'), iv: iv.toString('base64'), ciphertext: ciphertext.toString('base64'), tag: cipher.getAuthTag().toString('base64') };
}

test('recipient generation is asynchronous, RSA-3072 SPKI-bound and private material stays only in its native record', async () => {
  let progressed = false; setImmediate(() => { progressed = true; });
  const recipient = await recipientPromise;
  assert.equal(progressed, true); assert.equal(Object.isFrozen(recipient), true);
  const key = crypto.createPublicKey(recipient.publicKey);
  assert.equal(key.asymmetricKeyDetails.modulusLength, 3072); assert.equal(key.asymmetricKeyDetails.publicExponent, 65537n);
  assert.equal(recipient.recipientSha256, crypto.createHash('sha256').update(key.export({ type: 'spki', format: 'der' })).digest('hex'));
  await assert.rejects(createRecipient({ modulusLength: 2048 }), rejected);
  for (const options of [null, [], 1, { extra: true }, { get modulusLength() { throw Error('private-message'); } }])
    await assert.rejects(createRecipient(options), rejected);
});

test('actual PHP OpenSSL seals credentials and complete UTF-8 membership for Node GCM/OAEP decryption', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, value = privatePayload(), raw = sealed(recipient, value), envelope = parseEnvelope(raw);
  assert.deepEqual(decryptEnrollment(raw, options(recipient)), value);
  assert.deepEqual(decryptEnrollment(Buffer.from(raw), options(recipient)), value);
  assert.deepEqual(decryptEnrollment(envelope, options(recipient)), value);
  for (const secret of [value.credential.token, value.manifest.signature, value.manifest.members[0].grant,
    value.manifest.members[0].password_binding, value.manifest.members[0].aliases[0]]) assert.equal(raw.includes(secret), false);
  assert.equal(envelope.header.recipient_sha256, recipient.recipientSha256);
  assert.equal(Buffer.from(envelope.encrypted_key, 'base64').length, 384);
  assert.equal(Buffer.from(envelope.iv, 'base64').length, 12); assert.equal(Buffer.from(envelope.tag, 'base64').length, 16);
  const next = sealed(recipient, value); assert.notEqual(raw, next, 'Every seal uses a fresh key and IV.');
});

test('RSA-4096 transports a large membership payload through the same bounded hybrid suite', { skip: !phpAvailable }, async () => {
  const recipient = await createRecipient({ modulusLength: 4096 }), value = privatePayload();
  value.manifest.members[0].permissions.names = Array.from({ length: 2000 }, (_, index) =>
    'permission-'+String(index).padStart(4, '0')+'-'+('x'.repeat(80)));
  value.manifest.roster_sha256 = digest(value.manifest.members);
  const raw = sealed(recipient, value), envelope = parseEnvelope(raw);
  assert.ok(Buffer.byteLength(canonical(value)) > 128 * 1024);
  assert.equal(Buffer.from(envelope.encrypted_key, 'base64').length, 512);
  assert.deepEqual(decryptEnrollment(raw, options(recipient)), value);
});

test('a valid multi-megabyte enrollment stays usable near the supported manifest budget', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, value = privatePayload(), sample = value.manifest.members[0];
  const names = Array.from({ length: 4096 }, (_, index) => 'permission-'+String(index).padStart(4, '0')+'-'+('x'.repeat(142)));
  value.manifest.members = Array.from({ length: 20 }, (_, index) => ({ ...sample, actor_id: 1000 + index,
    aliases: ['cached-'+String(index).padStart(4, '0')+'@example.test'],
    permissions: { super_admin: false, names, roles: ['operator'] } }));
  value.manifest.roster_sha256 = digest(value.manifest.members);
  const raw = sealed(recipient, value);
  assert.ok(Buffer.byteLength(raw) > 16 * 1024 ** 2);
  const opened = decryptEnrollment(raw, options(recipient));
  assert.equal(opened.manifest.members.length, 20);
  assert.equal(digest(opened.manifest), digest(value.manifest));
  assert.deepEqual(opened.credential, value.credential);
});

test('expected recipient, workspace, nonce and pinned HTTPS origin cannot be substituted', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, raw = sealed(recipient);
  for (const changed of [{ recipient: await secondRecipientPromise }, { workspaceId: '22222222-2222-4222-8222-222222222222' },
    { nonce: 'a'.repeat(64) }, { serverOrigin: 'https://foreign.example.test' }, { serverOrigin: serverOrigin+'/' }, { serverOrigin: 'http://dashboard.example.test' }])
    assert.throws(() => decryptEnrollment(raw, { ...options(recipient), ...changed }), rejected);
  const altered = { ...recipient, privateKey: (await secondRecipientPromise).privateKey };
  assert.throws(() => decryptEnrollment(raw, { ...options(recipient), recipient: altered }), rejected);
});

test('all authenticated header and ciphertext fields reject tampering before any private payload is returned', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, original = parseEnvelope(sealed(recipient));
  for (const field of ['encrypted_key', 'iv', 'tag', 'ciphertext']) {
    const value = structuredClone(original), bytes = Buffer.from(value[field], 'base64'); bytes[0] ^= 1; value[field] = bytes.toString('base64');
    assert.throws(() => decryptEnrollment(value, options(recipient)), rejected, field);
  }
  for (const [field, value] of [['format', 1], ['kind', 'foreign'], ['recipient_sha256', 'a'.repeat(64)], ['nonce_sha256', 'd'.repeat(64)]]) {
    const copy = structuredClone(original); copy.header[field] = value; assert.throws(() => decryptEnrollment(copy, options(recipient)), rejected);
  }
  for (const field of ['header', 'encrypted_key', 'iv', 'tag', 'ciphertext']) {
    const copy = structuredClone(original); delete copy[field]; assert.throws(() => decryptEnrollment(copy, options(recipient)), rejected);
  }
});

test('wire parsing rejects duplicate/unknown fields, noncanonical base64, tag lengths and oversized data', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, raw = sealed(recipient), original = parseEnvelope(raw);
  for (const value of [raw+'\n', '{"header":{},'+raw.slice(1), JSON.stringify({ ...original, public_token: 'secret' }),
    canonical({ ...original, header: { ...original.header, actor_id: 1 } }), Buffer.from([0xff, 0xfe])])
    assert.throws(() => decryptEnrollment(value, options(recipient)), rejected);
  for (const [field, value] of [['tag', Buffer.alloc(15).toString('base64')], ['iv', Buffer.alloc(11).toString('base64')],
    ['encrypted_key', Buffer.alloc(383).toString('base64')], ['ciphertext', original.ciphertext+' '],
    ['ciphertext', original.ciphertext.replace(/=+$/, '')], ['tag', original.tag.replace(/=+$/, '')],
    ['ciphertext', 'a'.repeat(4 * Math.ceil(MAX_PLAINTEXT_BYTES / 3) + 4)]]) {
    if (value === original[field]) continue;
    assert.throws(() => decryptEnrollment({ ...original, [field]: value }, options(recipient)), rejected);
  }
  assert.throws(() => parseEnvelope(' '.repeat(MAX_ENVELOPE_BYTES + 1)), rejected);
});

test('the PHP wire serializer refuses a private payload or unexpected plaintext fields', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, envelope = parseEnvelope(sealed(recipient));
  const source = path.join(__dirname, '../../app/Services/Dashboard/DesktopDashboardWorkspaceEnrollmentSeal.php');
  const code = 'require $argv[1]; try { echo App\\Services\\Dashboard\\DesktopDashboardWorkspaceEnrollmentSeal::encode(json_decode(stream_get_contents(STDIN), true, 128, JSON_THROW_ON_ERROR)); } catch (Throwable $error) { fwrite(STDERR, "ENVELOPE_REJECTED\\n"); exit(1); }';
  for (const value of [privatePayload(), { ...envelope, credential: privatePayload().credential },
    { ...envelope, header: { ...envelope.header, token: privatePayload().credential.token } }, { ...envelope, ciphertext: { token: 'private' } }]) {
    const result = spawnSync(php, ['-n', '-r', code, source], { input: JSON.stringify(value), encoding: 'utf8', timeout: 10000 });
    assert.equal(result.status, 1); assert.equal(result.stdout, ''); assert.equal(result.stderr, 'ENVELOPE_REJECTED\n');
  }
});

test('PHP validates public key types, key size and enrollment bindings and never returns diagnostics or payload secrets', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, value = privatePayload();
  const weak = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 }).publicKey.export({ type: 'spki', format: 'pem' });
  const ec = crypto.generateKeyPairSync('ec', { namedCurve: 'prime256v1' }).publicKey.export({ type: 'spki', format: 'pem' });
  for (const request of [{ public_key: weak }, { public_key: ec }, { public_key: recipient.privateKey },
    { public_key: 'not-a-public-key' }, { nonce: 'A'.repeat(64) }, { workspace_id: '../escape' },
    { payload: { ...value, password: 'never-export-password' } },
    { payload: { ...value, credential: { ...value.credential, workspaceId: '22222222-2222-4222-8222-222222222222' } } },
    { payload: { ...value, credential: { ...value.credential, serverOrigin: serverOrigin+'/path' } } },
    { payload: { ...value, credential: { ...value.credential, serverOrigin: 'https://DASHBOARD.example.test' } } },
    { payload: { ...value, credential: { ...value.credential, serverOrigin: serverOrigin+':443' } } },
    { payload: { ...value, manifest: { ...value.manifest, signature: 'invalid' } } }]) {
    const result = seal(recipient, value, request); assert.equal(result.status, 1); assert.equal(result.stdout, '');
    assert.equal(result.stderr, 'WORKSPACE_ENROLLMENT_SEAL_FIXTURE_REJECTED\n');
  }
  const leaked = structuredClone(value); leaked.manifest.members[0].password = 'never-export-password';
  leaked.manifest.roster_sha256 = digest(leaked.manifest.members);
  const result = seal(recipient, leaked); assert.equal(result.status, 1); assert.equal(result.stdout, '');
  for (const change of [member => { member.aliases = ['x'.repeat(321)]; },
    member => { member.permissions.names = ['x'.repeat(4097)]; }, member => { member.aliases = ['\u00a0lower@example.test']; },
    member => { member.aliases = ['Éxample@example.test']; }]) {
    const bad = privatePayload(); change(bad.manifest.members[0]); bad.manifest.roster_sha256 = digest(bad.manifest.members);
    const refused = seal(recipient, bad); assert.equal(refused.status, 1); assert.equal(refused.stdout, '');
  }
});

test('transport preserves the authority manifest permission/role strings without inventing shorter or trimmed limits', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, value = privatePayload();
  value.manifest.members[0].permissions.names = [' '+('x'.repeat(200))+' ', 'a\u2028b', 'a\u2029b'];
  value.manifest.members[0].permissions.roles = [' role name ', 'role\u2028separator'];
  value.manifest.roster_sha256 = digest(value.manifest.members);
  assert.deepEqual(decryptEnrollment(sealed(recipient, value), options(recipient)), value);
});

test('enrollment encryption adds no authority claim or registry/candidate activation side effect', { skip: !phpAvailable }, async () => {
  const recipient = await recipientPromise, value = privatePayload();
  // A schema-valid synthetic signature remains opaque: this helper must not
  // pretend that encryption checked the issuer, membership or source authority.
  value.manifest.signature = 'f'.repeat(64);
  const opened = decryptEnrollment(sealed(recipient, value), options(recipient));
  assert.equal(opened.manifest.signature, value.manifest.signature);
  assert.deepEqual(Object.keys(opened).sort(), ['credential', 'manifest']);
  assert.equal(opened.authorized, undefined); assert.equal(opened.verified, undefined); assert.equal(opened.prepared, undefined);
});

test('even valid GCM ciphertext cannot bypass private payload shape, canonical JSON or UTF-8 checks', async () => {
  const recipient = await recipientPromise, value = privatePayload(), raw = canonical(value);
  const badMember = structuredClone(value); badMember.manifest.members[0].password = 'never-export-password';
  badMember.manifest.roster_sha256 = digest(badMember.manifest.members);
  for (const bytes of [Buffer.from(canonical({ ...value, verified: true })), Buffer.from(canonical(badMember)),
    Buffer.from(' '+raw), Buffer.from('{"credential":{},'+raw.slice(1)), Buffer.from([0xff, 0xfe])])
    assert.throws(() => decryptEnrollment(syntheticSeal(recipient, bytes), options(recipient)), rejected);
  // Anyone with a public key can encrypt. A well-shaped untrusted payload is
  // intentionally not labelled authorized; only the native trusted-channel /
  // registry authority callback may make that separate decision.
  const opaque = decryptEnrollment(syntheticSeal(recipient, Buffer.from(raw)), options(recipient));
  assert.deepEqual(opaque, value); assert.equal(opaque.authorized, undefined);
  for (const invalidOptions of [undefined, null, [], 1, { get recipient() { throw Error('private-message'); }, workspaceId, nonce, serverOrigin }])
    assert.throws(() => decryptEnrollment(syntheticSeal(recipient, Buffer.from(raw)), invalidOptions), rejected);
});
