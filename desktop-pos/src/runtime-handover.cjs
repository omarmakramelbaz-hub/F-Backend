'use strict';
const fs = require('node:fs/promises');
const fsSync = require('node:fs');
const path = require('node:path');

const revision = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);

/**
 * Last-resort move to a newly installed program when the automatic promotion
 * keeps the device on an older retained runtime. Nothing is deleted: the old
 * device profile is renamed aside, and the device is prepared again from the server.
 */
class RuntimeHandover {
  constructor({ profile, now = () => new Date() }) {
    if (!path.isAbsolute(profile || '')) throw Error('مسار بيانات الجهاز غير صالح.');
    this.profile = profile; this.now = now;
    this.dashboard = path.join(profile, 'dashboard');
    this.marker = path.join(profile, 'runtime-handover.json');
  }
  static mismatch(installedRevision, activeRevision) {
    return revision(installedRevision) && revision(activeRevision) && installedRevision !== activeRevision;
  }
  async request({ from, to, version }) {
    if (!RuntimeHandover.mismatch(to, from)) throw Error('لا توجد نسخة جديدة مختلفة لنقل الجهاز إليها.');
    const value = { format: 1, from, to, version: String(version || ''), requestedAt: this.now().toISOString() };
    const temporary = this.marker + '.tmp';
    await fs.writeFile(temporary, JSON.stringify(value), { mode: 0o600 });
    await fs.rename(temporary, this.marker);
    return value;
  }
  pending() {
    try {
      const value = JSON.parse(fsSync.readFileSync(this.marker, 'utf8'));
      return value?.format === 1 && revision(value.from) && revision(value.to) ? value : null;
    } catch (error) { if (error.code === 'ENOENT') return null; throw error; }
  }
  /** Runs synchronously at startup, before the local runtime opens any file in the old profile. */
  apply() {
    if (!fsSync.existsSync(this.marker)) return { applied: false };
    const discard = () => { try { fsSync.unlinkSync(this.marker); } catch {} };
    let value;
    try { value = this.pending(); } catch { discard(); return { applied: false }; }
    if (!value) { discard(); return { applied: false }; }
    const stamp = this.now().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
    const retired = path.join(this.profile, 'dashboard-retired-' + value.from.slice(0, 8) + '-' + stamp);
    try { fsSync.renameSync(this.dashboard, retired); }
    catch (error) {
      if (error.code !== 'ENOENT') return { applied: false, error: error.message };
      fsSync.unlinkSync(this.marker);
      return { applied: true, retired: null, request: value };
    }
    fsSync.unlinkSync(this.marker);
    return { applied: true, retired, request: value };
  }
}

module.exports = RuntimeHandover;
