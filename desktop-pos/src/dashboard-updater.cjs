'use strict';
const fs = require('node:fs/promises');
const constants = require('node:fs').constants;
const path = require('node:path');
const crypto = require('node:crypto');
const source = require('./dashboard-source.cjs');

const POLL_MS = 60000, MANIFEST_TIMEOUT_MS = 60000, INSTALLER_TIMEOUT_MS = 30 * 60000;
const MAX_JSON = 16384, MAX_INSTALLER = 1024 ** 3;
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const revision = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);
const exact = (value, keys) => value && typeof value === 'object' && !Array.isArray(value)
  && Object.keys(value).sort().join(',') === keys.slice().sort().join(',');
const fingerprint = value => exact(value, ['format','files','sha256','framework']) && hash(value.sha256) && source.valid(value);
function semver(value) {
  if (typeof value !== 'string' || value.length > 100) return null;
  const found = /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/.exec(value);
  if (!found) return null;
  const numbers = found.slice(1, 4).map(Number), pre = found[4]?.split('.') || [];
  if (numbers.some(number => !Number.isSafeInteger(number)) || pre.some(part => /^[0-9]+$/.test(part)
    && (part.length > 1 && part[0] === '0' || !Number.isSafeInteger(Number(part))))) return null;
  return { numbers, pre };
}
function compareVersions(left, right) {
  const a = semver(left), b = semver(right);
  if (!a || !b) throw Error('إصدار تحديث البرنامج غير صالح.');
  for (let i = 0; i < 3; i++) if (a.numbers[i] !== b.numbers[i]) return a.numbers[i] > b.numbers[i] ? 1 : -1;
  if (!a.pre.length || !b.pre.length) return a.pre.length === b.pre.length ? 0 : a.pre.length ? -1 : 1;
  for (let i = 0; i < Math.max(a.pre.length, b.pre.length); i++) {
    if (a.pre[i] === undefined || b.pre[i] === undefined) return a.pre[i] === undefined ? -1 : 1;
    if (a.pre[i] === b.pre[i]) continue;
    const an = /^[0-9]+$/.test(a.pre[i]), bn = /^[0-9]+$/.test(b.pre[i]);
    if (an && bn) return Number(a.pre[i]) > Number(b.pre[i]) ? 1 : -1;
    if (an !== bn) return an ? -1 : 1;
    return a.pre[i] > b.pre[i] ? 1 : -1;
  }
  return 0;
}
function validateManifest(value) {
  if (!exact(value, ['format','poll_seconds','state','server_source','release']) || value.format !== 1
    || value.poll_seconds !== 60 || !fingerprint(value.server_source) || !['ready','unavailable'].includes(value.state))
    throw Error('بيان تحديث البرنامج غير صالح.');
  if (value.state === 'unavailable') {
    if (value.release !== null) throw Error('بيان التحديث غير المتاح غير صالح.');
    return value;
  }
  const release = value.release;
  if (!exact(release, ['version','sequence','source_revision','source','installer']) || !semver(release.version)
    || !Number.isSafeInteger(release.sequence) || release.sequence < 1
    || !revision(release.source_revision) || !fingerprint(release.source)
    || !source.same(release.source, value.server_source)
    || !exact(release.installer, ['sha256','bytes','path']) || !hash(release.installer.sha256)
    || !Number.isSafeInteger(release.installer.bytes) || release.installer.bytes < 1 || release.installer.bytes > MAX_INSTALLER
    || release.installer.path !== '/api/desktop-dashboard/update/installer/' + release.installer.sha256)
    throw Error('نسخة تحديث البرنامج لا تطابق السيرفر.');
  return value;
}
const canonical = value => value === null || typeof value !== 'object' ? JSON.stringify(value)
  : Array.isArray(value) ? '[' + value.map(canonical).join(',') + ']'
    : '{' + Object.keys(value).sort().map(key => JSON.stringify(key) + ':' + canonical(value[key])).join(',') + '}';
const digest = value => crypto.createHash('sha256').update(canonical(value)).digest('hex');
function credentialBinding(value) {
  let origin;
  try { origin = new URL(value.serverOrigin); } catch { throw Error('ربط سيرفر تحديث البرنامج غير مكتمل.'); }
  if (origin.protocol !== 'https:' || origin.username || origin.password || origin.origin !== value.serverOrigin || !hash(value.token))
    throw Error('ربط سيرفر تحديث البرنامج غير صالح.');
  return { serverOrigin: value.serverOrigin, token: value.token };
}
function aborted(signal) { if (signal.aborted) throw Error('تم إيقاف تحديث البرنامج.'); }
async function readBounded(response, maximum, signal, write) {
  if (!response.body || typeof response.body.getReader !== 'function') throw Error('تعذر قراءة ملف التحديث.');
  const reader = response.body.getReader(); let bytes = 0;
  const cancel = () => { reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  try {
    while (true) {
      aborted(signal);
      const part = await reader.read();
      aborted(signal);
      if (part.done) break;
      if (!(part.value instanceof Uint8Array)) throw Error('بيانات التحديث غير صالحة.');
      bytes += part.value.byteLength;
      if (bytes > maximum) throw Error('حجم التحديث تجاوز الحد المسموح.');
      await write(Buffer.from(part.value));
    }
    return bytes;
  } finally { signal.removeEventListener('abort', cancel); await reader.cancel().catch(() => {}); reader.releaseLock(); }
}

/** The native caller owns the idle/outbox gate and the installer handoff. No journal is changed here. */
class DashboardUpdater {
  constructor({ profile, metadata, credential, fetch: transport = globalThis.fetch, currentVersion, currentSource, currentRevision,
    canApply, apply, onState = () => {}, setTimer = setTimeout, clearTimer = clearTimeout }) {
    if (!path.isAbsolute(profile) || !metadata?.read || !metadata?.write || typeof credential !== 'function'
      || typeof transport !== 'function' || typeof canApply !== 'function' || typeof apply !== 'function'
      || !(revision(currentRevision) || typeof currentRevision === 'function')) throw Error('إعداد تحديث البرنامج غير مكتمل.');
    Object.assign(this, { profile, metadata, credential, transport, currentVersion, currentSource, currentRevision, canApply, apply, onState, setTimer, clearTimer });
    this.directory = path.join(profile, 'updates'); this.state = { status: 'idle', interval_seconds: 60 }; this.active = false; this.epoch = 0;
    this.authorizations = new Set(); this.highwaterTail = Promise.resolve();
  }
  report(value) { this.state = { ...value, interval_seconds: 60 }; this.onState({ ...this.state }); return this.state; }
  start() {
    if (this.active) return this.running || Promise.resolve(this.state);
    this.active = true;
    return this.check();
  }
  stop() {
    this.active = false; this.epoch++; this.clearTimer(this.timer); this.timer = null; this.controller?.abort();
    for (const controller of this.authorizations) controller.abort();
    this.authorizations.clear();
  }
  check() {
    if (this.running) return this.running;
    this.clearTimer(this.timer); this.timer = null;
    const controller = new AbortController(), epoch = this.epoch; this.controller = controller;
    const task = this.inspect(controller.signal, epoch).catch(error => {
      if (epoch === this.epoch && !controller.signal.aborted) this.report({ status: 'error', error: error.code
        ? 'تعذر التحقق من ملفات تحديث البرنامج؛ بيانات الجهاز محفوظة.' : error.message });
      return this.state;
    }).finally(() => {
      if (this.running === task) this.running = null;
      if (this.controller === controller) this.controller = null;
      if (this.active && epoch === this.epoch) this.timer = this.setTimer(() => { this.timer = null; this.check(); }, POLL_MS);
    });
    this.running = task; return task;
  }
  ensure(signal, epoch) { aborted(signal); if (epoch !== this.epoch) throw Error('تم إيقاف تحديث البرنامج.'); }
  async authorizeInstalled(installed) {
    if (!this.active || !revision(installed?.sourceRevision) || !fingerprint(installed?.sourceFingerprint)) return false;
    const epoch = this.epoch, controller = new AbortController(); this.authorizations.add(controller);
    try {
      const binding = credentialBinding(await this.credential()); this.ensure(controller.signal, epoch);
      const manifest = await this.manifest(binding, controller.signal); this.ensure(controller.signal, epoch);
      if (manifest.state !== 'ready') return false;
      await this.highwater(binding, manifest); this.ensure(controller.signal, epoch);
      const installedVersion = typeof this.currentVersion === 'function' ? await this.currentVersion() : this.currentVersion;
      const installedRevision = typeof this.currentRevision === 'function' ? await this.currentRevision() : this.currentRevision;
      if (!revision(installedRevision) || manifest.release.source_revision !== installed.sourceRevision
        || installed.sourceRevision !== installedRevision || manifest.release.version !== installedVersion
        || !source.same(manifest.release.source, installed.sourceFingerprint)) return false;
      if (canonical(credentialBinding(await this.credential())) !== canonical(binding)) return false;
      this.ensure(controller.signal, epoch);
      return this.active === true;
    } catch { return false; }
    finally { this.authorizations.delete(controller); }
  }
  async request(binding, pathname, signal, accept, timeout = MANIFEST_TIMEOUT_MS) {
    const url = binding.serverOrigin + pathname;
    const requestSignal = AbortSignal.any([signal, AbortSignal.timeout(timeout)]);
    const response = await this.transport(url, { method: 'GET', redirect: 'error', cache: 'no-store', credentials: 'omit',
      headers: { Accept: accept, Authorization: 'Bearer ' + binding.token, 'Accept-Encoding': 'identity' },
      signal: requestSignal });
    if (requestSignal.aborted) { await response?.body?.cancel().catch(() => {}); aborted(requestSignal); }
    if (!response || response.status !== 200 || response.redirected || response.url && response.url !== url) {
      await response?.body?.cancel().catch(() => {});
      throw Error('لم يؤكد السيرفر رابط التحديث الأصلي.');
    }
    return { response, signal: requestSignal };
  }
  async manifest(binding, signal) {
    const { response, signal: deadline } = await this.request(binding, '/api/desktop-dashboard/update', signal, 'application/json');
    const declared = response.headers?.get('content-length');
    if (declared !== null && declared !== undefined && (!/^[0-9]+$/.test(declared) || Number(declared) > MAX_JSON)) throw Error('بيان التحديث أكبر من الحد المسموح.');
    const parts = []; await readBounded(response, MAX_JSON, deadline, bytes => { parts.push(bytes); });
    let result; try { result = JSON.parse(Buffer.concat(parts).toString('utf8')); } catch { throw Error('تعذر قراءة بيان تحديث البرنامج.'); }
    return validateManifest(result);
  }
  highwater(binding, manifest) {
    // Snapshot authorization can run beside the poll. Serialize the encrypted
    // watermark read/write so an older concurrent response cannot lower it.
    const task = this.highwaterTail.then(() => this.acceptHighwater(binding, manifest));
    this.highwaterTail = task.catch(() => {});
    return task;
  }
  async acceptHighwater(binding, manifest) {
    const key = 'desktop-updates/' + crypto.createHash('sha256').update(binding.serverOrigin).digest('hex');
    const previous = await this.metadata.read(key), release = manifest.release, currentDigest = digest(release);
    if (previous !== null && previous !== undefined && (!exact(previous, ['format','sequence','digest','version'])
      || previous.format !== 1 || !Number.isSafeInteger(previous.sequence) || previous.sequence < 1 || !hash(previous.digest) || !semver(previous.version)))
      throw Error('سجل إصدار تحديث البرنامج يحتاج المراجعة.');
    if (previous && (release.sequence < previous.sequence || release.sequence === previous.sequence && currentDigest !== previous.digest
      || compareVersions(release.version, previous.version) < 0)) throw Error('رفض البرنامج إصدار تحديث أقدم أو متغير.');
    if (!previous || release.sequence > previous.sequence) await this.metadata.write(key, { format: 1, sequence: release.sequence, digest: currentDigest, version: release.version });
    return currentDigest;
  }
  async verify(file, installer, signal) {
    aborted(signal);
    const named = await fs.lstat(file);
    if (!named.isFile() || named.isSymbolicLink()) throw Error('ملف تحديث البرنامج غير صالح.');
    const handle = await fs.open(file, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
    try {
      const before = await handle.stat();
      if (!before.isFile() || before.size !== installer.bytes || before.dev !== named.dev || before.ino !== named.ino) throw Error('حجم ملف التحديث غير مطابق.');
      const result = crypto.createHash('sha256'); let read = 0;
      for await (const bytes of handle.createReadStream({ autoClose: false })) { aborted(signal); read += bytes.length; if (read > installer.bytes) throw Error('ملف التحديث تغير أثناء الفحص.'); result.update(bytes); }
      const after = await handle.stat(), current = await fs.lstat(file);
      if (read !== installer.bytes || result.digest('hex') !== installer.sha256 || after.size !== before.size || after.mtimeMs !== before.mtimeMs
        || !current.isFile() || current.isSymbolicLink() || current.dev !== before.dev || current.ino !== before.ino) throw Error('ملف تحديث البرنامج غير مكتمل أو تغير.');
    } finally { await handle.close(); }
    return file;
  }
  async stage(binding, release, signal) {
    await fs.mkdir(this.directory, { recursive: true, mode: 0o700 });
    const directory = await fs.lstat(this.directory);
    if (!directory.isDirectory() || directory.isSymbolicLink()) throw Error('مجلد تحديث البرنامج غير صالح.');
    const installer = release.installer, file = path.join(this.directory, installer.sha256 + '.exe');
    try { await fs.lstat(file); return await this.verify(file, installer, signal); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    this.report({ status: 'downloading', version: release.version });
    const temporary = path.join(this.directory, installer.sha256 + '.' + crypto.randomBytes(16).toString('hex') + '.tmp');
    const handle = await fs.open(temporary, constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | (constants.O_NOFOLLOW || 0), 0o600);
    try {
      const { response, signal: deadline } = await this.request(binding, installer.path, signal, 'application/octet-stream', INSTALLER_TIMEOUT_MS);
      const declared = response.headers?.get('content-length');
      if (declared !== null && declared !== undefined && (!/^[0-9]+$/.test(declared) || Number(declared) !== installer.bytes)) throw Error('حجم تنزيل التحديث غير مطابق.');
      const downloaded = crypto.createHash('sha256');
      const bytes = await readBounded(response, installer.bytes, deadline, async chunk => {
        downloaded.update(chunk); let offset = 0;
        while (offset < chunk.length) { const { bytesWritten } = await handle.write(chunk, offset); if (!bytesWritten) throw Error('تعذر حفظ ملف التحديث.'); offset += bytesWritten; }
      });
      if (bytes !== installer.bytes || downloaded.digest('hex') !== installer.sha256) throw Error('بصمة ملف التحديث غير مطابقة.');
      await handle.sync(); await handle.close();
      await this.verify(temporary, installer, signal); aborted(signal);
      await fs.rename(temporary, file);
      return await this.verify(file, installer, signal);
    } finally { await handle.close().catch(() => {}); await fs.unlink(temporary).catch(() => {}); }
  }
  async inspect(signal, epoch) {
    this.report({ status: 'checking' });
    const binding = credentialBinding(await this.credential()); this.ensure(signal, epoch);
    const manifest = await this.manifest(binding, signal); this.ensure(signal, epoch);
    if (manifest.state === 'unavailable') return this.report({ status: 'unavailable', server_source: manifest.server_source });
    const release = manifest.release, releaseDigest = await this.highwater(binding, manifest); this.ensure(signal, epoch);
    const currentVersion = typeof this.currentVersion === 'function' ? await this.currentVersion() : this.currentVersion;
    const comparison = compareVersions(release.version, currentVersion);
    if (comparison < 0) throw Error('نسخة البرنامج الحالية أحدث من التحديث المتاح.');
    const currentSource = typeof this.currentSource === 'function' ? await this.currentSource() : this.currentSource;
    const currentRevision = typeof this.currentRevision === 'function' ? await this.currentRevision() : this.currentRevision;
    if (!revision(currentRevision)) throw Error('لم يتأكد مصدر نسخة البرنامج المثبتة؛ بيانات الجهاز محفوظة.');
    this.ensure(signal, epoch);
    if (comparison === 0 && source.same(currentSource, release.source) && currentRevision === release.source_revision)
      return this.report({ status: 'current', version: currentVersion, server_source: manifest.server_source });
    if (this.appliedDigest === releaseDigest) return this.report({ status: 'installing', version: release.version });
    const file = await this.stage(binding, release, signal); this.ensure(signal, epoch);
    const staged = { status: 'staged', version: release.version, server_source: manifest.server_source };
    this.report(staged);
    if ((await this.canApply()) !== true) return this.state;
    this.ensure(signal, epoch);
    const fresh = await this.manifest(binding, signal); this.ensure(signal, epoch);
    if (fresh.state === 'ready') await this.highwater(binding, fresh);
    this.ensure(signal, epoch);
    if (fresh.state !== 'ready' || digest(fresh.release) !== releaseDigest) return this.report({ status: 'unavailable', server_source: fresh.server_source });
    if (canonical(credentialBinding(await this.credential())) !== canonical(binding)) throw Error('تغير ربط الجهاز أثناء تحديث البرنامج.');
    await this.verify(file, release.installer, signal); this.ensure(signal, epoch);
    if ((await this.canApply()) !== true) return this.report(staged);
    this.ensure(signal, epoch);
    this.report({ status: 'installing', version: release.version });
    const result = await this.apply({ file, release });
    if (result?.deferred === true) return this.report(staged);
    this.appliedDigest = releaseDigest;
    return this.state;
  }
}
module.exports = { DashboardUpdater, validateManifest, compareVersions, POLL_MS, MANIFEST_TIMEOUT_MS, INSTALLER_TIMEOUT_MS, MAX_JSON, MAX_INSTALLER };
