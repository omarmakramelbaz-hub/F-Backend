'use strict';

// Main-process-only enrollment transport. Decryption authenticates ciphertext
// integrity and recipient/context, not the server's authority. The registry's
// mandatory fresh-authority and candidate/fence callbacks remain separate.
// PHP8.2 OpenSSL OAEP uses SHA-1/MGF1 with an empty label; select it explicitly.
// https://nodejs.org/docs/latest-v24.x/api/crypto.html#cryptoprivatedecryptprivatekey-buffer
const crypto = require('node:crypto');
const { manifest, canonical } = require('./workspace-profile.cjs');
const MAX_PLAINTEXT_BYTES = 17 * 1024 ** 2, MAX_ENVELOPE_BYTES = 24 * 1024 ** 2;
const KIND = 'desktop-dashboard-workspace-enrollment-seal';
const RECIPIENT_KIND = 'desktop-dashboard-workspace-enrollment-recipient';
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value)
  && [Object.prototype, null].includes(Object.getPrototypeOf(value));
const exact = (value, keys) => object(value) && Object.keys(value).sort().join(',') === [...keys].sort().join(',');
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const invalid = () => Object.assign(Error('The private workspace enrollment could not be verified.'), { code: 'WORKSPACE_ENROLLMENT_SEAL_INVALID' });
const need = value => { if (!value) throw invalid(); };
const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
function origin(value) {
  let parsed; try { parsed = new URL(value); } catch { throw invalid(); }
  need(typeof value === 'string' && value.length <= 2048 && parsed.protocol === 'https:' && parsed.origin === value && !parsed.username && !parsed.password); return value;
}
function rsa(key, type) {
  need(key?.type === type && key.asymmetricKeyType === 'rsa' && [3072, 4096].includes(key.asymmetricKeyDetails?.modulusLength)
    && key.asymmetricKeyDetails?.publicExponent === 65537n); return key;
}
function publicInfo(publicKey) {
  need(typeof publicKey === 'string' && publicKey.length <= 2048 && /^-----BEGIN PUBLIC KEY-----\r?\n[A-Za-z0-9+/=\r\n]+-----END PUBLIC KEY-----\r?\n?$/.test(publicKey));
  const key = rsa(crypto.createPublicKey(publicKey), 'public'), der = key.export({ type: 'spki', format: 'der' });
  return { key, sha256: digest(der), bytes: key.asymmetricKeyDetails.modulusLength / 8 };
}
/** The returned privateKey must stay native and be persisted only through protected native storage. */
async function createRecipient(options = {}) {
  try {
    need(object(options) && Object.keys(options).every(name => name === 'modulusLength'));
    const { modulusLength = 3072 } = options; need([3072, 4096].includes(modulusLength));
    const pair = await new Promise((resolve, reject) => crypto.generateKeyPair('rsa', { modulusLength, publicExponent: 0x10001 },
      (error, publicKey, privateKey) => error ? reject(error) : resolve({ publicKey, privateKey })));
    const publicKey = pair.publicKey.export({ type: 'spki', format: 'pem' }), privateKey = pair.privateKey.export({ type: 'pkcs8', format: 'pem' });
    return Object.freeze({ format: 1, kind: RECIPIENT_KIND, publicKey, privateKey, recipientSha256: publicInfo(publicKey).sha256 });
  } catch { throw invalid(); }
}
function base64(value, maximum, expected) {
  need(typeof value === 'string' && value.length > 0 && value.length <= 4 * Math.ceil(maximum / 3)
    && value.length % 4 === 0 && /^[A-Za-z0-9+/]*={0,2}$/.test(value));
  const decoded = Buffer.from(value, 'base64');
  need(decoded.length <= maximum && (expected === undefined || decoded.length === expected) && decoded.toString('base64') === value); return decoded;
}
/** Canonical wire parsing rejects duplicate fields, trailing bytes and ambiguous JSON encodings. */
function parseEnvelope(value) {
  try {
    if (typeof value === 'string' || Buffer.isBuffer(value)) {
      need(Buffer.byteLength(value) <= MAX_ENVELOPE_BYTES);
      const text = typeof value === 'string' ? value : new TextDecoder('utf-8', { fatal: true }).decode(value);
      const parsed = JSON.parse(text); need(canonical(parsed) === text); value = parsed;
    }
    need(exact(value, ['header', 'encrypted_key', 'iv', 'ciphertext', 'tag'])
      && exact(value.header, ['format', 'kind', 'workspace_id', 'recipient_sha256', 'nonce_sha256'])
      && value.header.format === 2 && value.header.kind === KIND && uuid(value.header.workspace_id)
      && hash(value.header.recipient_sha256) && hash(value.header.nonce_sha256));
    return value;
  } catch { throw invalid(); }
}
function payload(value, workspaceId, serverOrigin) {
  need(exact(value, ['credential', 'manifest'])
    && exact(value.credential, ['workspaceId', 'serverOrigin', 'token', 'enrollmentId'])
    && value.credential.workspaceId === workspaceId && origin(value.credential.serverOrigin) === serverOrigin
    && hash(value.credential.token) && hash(value.credential.enrollmentId));
  const checked = manifest(value.manifest, workspaceId);
  need(checked.root_fingerprint === value.credential.enrollmentId); return value;
}
/** No password, key, token, crypto diagnostic or plaintext is included in a rejection. */
function decryptEnrollment(envelope, options = {}) {
  let key, plaintext;
  try {
    need(exact(options, ['recipient', 'workspaceId', 'nonce', 'serverOrigin']));
    const { recipient, workspaceId, nonce, serverOrigin } = options;
    need(uuid(workspaceId) && hash(nonce)); origin(serverOrigin);
    need(exact(recipient, ['format', 'kind', 'publicKey', 'privateKey', 'recipientSha256'])
      && recipient.format === 1 && recipient.kind === RECIPIENT_KIND && hash(recipient.recipientSha256)
      && typeof recipient.privateKey === 'string' && recipient.privateKey.length <= 4096
      && /^-----BEGIN PRIVATE KEY-----\r?\n[A-Za-z0-9+/=\r\n]+-----END PRIVATE KEY-----\r?\n?$/.test(recipient.privateKey));
    const info = publicInfo(recipient.publicKey), privateKey = rsa(crypto.createPrivateKey(recipient.privateKey), 'private');
    need(info.sha256 === recipient.recipientSha256
      && digest(crypto.createPublicKey(privateKey).export({ type: 'spki', format: 'der' })) === info.sha256);
    const sealed = parseEnvelope(envelope), header = sealed.header;
    need(header.workspace_id === workspaceId && header.recipient_sha256 === info.sha256 && header.nonce_sha256 === digest(nonce));
    const wrapped = base64(sealed.encrypted_key, 512, info.bytes), iv = base64(sealed.iv, 12, 12), tag = base64(sealed.tag, 16, 16);
    const ciphertext = base64(sealed.ciphertext, MAX_PLAINTEXT_BYTES);
    need(Buffer.byteLength(canonical(sealed)) <= MAX_ENVELOPE_BYTES);
    key = crypto.privateDecrypt({ key: privateKey, padding: crypto.constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha1' }, wrapped);
    need(key.length === 32);
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv, { authTagLength: 16 });
    decipher.setAAD(Buffer.from(canonical(header))); decipher.setAuthTag(tag);
    plaintext = Buffer.concat([decipher.update(ciphertext), decipher.final()]);
    need(plaintext.length <= MAX_PLAINTEXT_BYTES);
    const raw = new TextDecoder('utf-8', { fatal: true }).decode(plaintext), parsed = JSON.parse(raw);
    need(canonical(parsed) === raw); return payload(parsed, workspaceId, serverOrigin);
  } catch { throw invalid(); }
  finally { key?.fill(0); plaintext?.fill(0); }
}

module.exports = { createRecipient, decryptEnrollment, parseEnvelope, MAX_PLAINTEXT_BYTES, MAX_ENVELOPE_BYTES };
