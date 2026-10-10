'use strict';
// CI-only synthetic metadata; uses real Electron main-process safeStorage.
const { app, safeStorage } = require('electron');
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');
const { GenerationStore } = require('../../desktop-pos/src/dashboard-generation.cjs');
const [phase, profile, input, output] = process.argv.slice(2);
const names = ['credentials', 'prepared', 'refresh'];
const requireProof = (condition, message) => { if (!condition) throw Error(message); };
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

require('node:fs').mkdirSync(path.join(profile, 'electron-user'), { recursive: true });
app.setPath('userData', path.join(profile, 'electron-user'));
app.setPath('sessionData', path.join(profile, 'electron-user'));
app.commandLine.appendSwitch('disable-gpu');

async function run() {
  requireProof(process.platform === 'win32', 'Real Windows is required.');
  requireProof(process.versions.electron === '44.6.0', 'Pinned Electron version differs.');
  requireProof(safeStorage.isEncryptionAvailable(), 'Windows safeStorage is unavailable.');
  const expected = JSON.parse(await fs.readFile(input, 'utf8'));
  const store = new GenerationStore(profile, safeStorage);
  const journal = await fs.readFile(path.join(profile, 'retained-journal.fixture'));
  const files = {};
  if (phase === 'write') {
    for (const name of names) await store.write(name, expected[name]);
    const connection = { origin: 'https://fixture.test', device_id: expected.credentials.deviceId,
      token_cipher: safeStorage.encryptString(expected.credentials.token).toString('base64') };
    await fs.writeFile(path.join(profile, 'connection.fixture.json'), JSON.stringify(connection), { flag: 'wx' });
  } else if (!['restart', 'corruption'].includes(phase)) {
    throw Error('Unknown acceptance phase.');
  }
  for (const name of names) {
    const bytes = await fs.readFile(path.join(profile, name + '.enc'));
    requireProof(bytes.length > 0, 'Encrypted metadata is empty.');
    requireProof(!bytes.includes(Buffer.from(expected.credentials.token)), 'Token appeared in encrypted metadata.');
    requireProof(!bytes.includes(Buffer.from(JSON.stringify(expected[name]))), 'Metadata was saved as plaintext.');
    requireProof(isDeepStrictEqual(await store.read(name), expected[name]), 'Metadata did not survive decryption.');
    files[name] = { bytes: bytes.length, sha256: sha(bytes) };
  }
  const connection = JSON.parse(await fs.readFile(path.join(profile, 'connection.fixture.json'), 'utf8'));
  requireProof(safeStorage.decryptString(Buffer.from(connection.token_cipher, 'base64')) === expected.credentials.token,
    'Main-process connection cipher format did not survive restart.');
  if (phase === 'corruption') {
    const bytes = Buffer.from(await fs.readFile(path.join(profile, 'credentials.enc')));
    bytes[bytes.length - 1] ^= 1;
    await fs.writeFile(path.join(profile, 'corrupted.enc'), bytes, { flag: 'wx' });
    let rejected = false;
    try { await store.read('corrupted'); } catch { rejected = true; }
    requireProof(rejected, 'Corrupted metadata was accepted.');
    requireProof(isDeepStrictEqual(await store.read('credentials'), expected.credentials), 'Corruption changed retained credentials.');
    await fs.unlink(path.join(profile, 'corrupted.enc'));
  }
  requireProof(sha(await fs.readFile(path.join(profile, 'retained-journal.fixture'))) === sha(journal), 'Retained journal bytes changed.');
  const receipt = { format: 1, phase, platform: process.platform, electron: process.versions.electron,
    processId: process.pid, realSafeStorage: true, metadataRoundTrip: true, connectionCipherRoundTrip: true,
    ciphertextContainsPlaintext: false, files, connectionCipherSha256: sha(Buffer.from(connection.token_cipher, 'base64')),
    journalSha256: sha(journal), corruptionRejected: phase === 'corruption' };
  await fs.writeFile(output, JSON.stringify(receipt, null, 2) + '\n');
  console.log('WINDOWS_SAFE_STORAGE_PHASE_COMPLETE ' + phase);
}

app.whenReady().then(run).then(() => app.exit(0)).catch(error => {
  console.error('WINDOWS_SAFE_STORAGE_PHASE_FAILED', phase, error.message);
  app.exit(1);
});
