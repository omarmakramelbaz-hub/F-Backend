'use strict';
const fs = require('node:fs/promises'), path = require('node:path');
const { rootBinding } = require('./workspace-mode.cjs');
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const error = code => Object.assign(Error('نتيجة عملية على السيرفر لم تتأكد؛ نسخة الجهاز وعملياتها محفوظة.'), { code: 'WORKSPACE_REMOTE_STATE_' + code });
const need = (value, code) => { if (!value) throw error(code); };
const states = new WeakMap(), KEY = 'workspace-remote-writes';
/** One durable uncertainty fence shared by every actor and every native wrapper. */
class WorkspaceRemoteState {
  constructor({ workspace, verifySnapshot }) {
    need(workspace?.metadata && typeof workspace.connection === 'function' && typeof verifySnapshot === 'function', 'CONFIGURATION_INVALID');
    Object.assign(this, { workspace, metadata: workspace.metadata, verifySnapshot });
    this.profile = this.metadata.storage?.profile || this.metadata.profile;
    need(typeof this.profile === 'string' && path.isAbsolute(this.profile), 'CONFIGURATION_INVALID');
    if (!states.has(workspace)) states.set(workspace, { tail: Promise.resolve(), blocked: false });
    this.queue = states.get(workspace);
  }
  serialize(work) { const task = this.queue.tail.then(work); this.queue.tail = task.catch(() => {}); return task; }
  get blocked() { return this.queue.blocked; }
  async root() { return rootBinding(await this.workspace.connection()); }
  async read() {
    const root = await this.root(), value = await this.metadata.read(KEY);
    if (value === null) {
      try { await fs.lstat(path.join(this.profile, 'workspace-remote-attempts')); throw error('INDEX_MISSING'); }
      catch (cause) { if (cause.code !== 'ENOENT') throw error('INDEX_MISSING'); }
      return { format: 2, kind: 'desktop-workspace-remote-state', workspaceId: root.workspaceId, rootFingerprint: root.rootFingerprint, dirty: false, pending: [] };
    }
    need(value?.format === 2 && value.kind === 'desktop-workspace-remote-state'
      && Object.keys(value).sort().join(',') === 'dirty,format,kind,pending,rootFingerprint,workspaceId'
      && value.workspaceId === root.workspaceId && value.rootFingerprint === root.rootFingerprint && typeof value.dirty === 'boolean'
      && Array.isArray(value.pending) && value.pending.length <= 10000 && value.pending.every(uuid) && new Set(value.pending).size === value.pending.length, 'INVALID');
    return structuredClone(value);
  }
  initialize() {
    return this.serialize(async () => {
      const value = await this.read();
      if (await this.metadata.read(KEY) === null) await this.metadata.write(KEY, value);
      return value;
    });
  }
  begin(id) {
    need(uuid(id), 'REQUEST_INVALID');
    return this.serialize(async () => {
      need(!this.blocked, 'SNAPSHOT_RUNNING'); const value = await this.read(); value.dirty = true;
      if (!value.pending.includes(id)) value.pending.push(id);
      need(value.pending.length <= 10000, 'CAPACITY'); await this.metadata.write(KEY, value);
    });
  }
  browse() {
    return this.serialize(async () => {
      need(!this.blocked, 'SNAPSHOT_RUNNING'); const value = await this.read();
      if (!value.dirty) { value.dirty = true; await this.metadata.write(KEY, value); }
    });
  }
  complete(id) {
    need(uuid(id), 'REQUEST_INVALID');
    return this.serialize(async () => {
      const value = await this.read(); if (!value.pending.includes(id)) return false;
      value.pending = value.pending.filter(current => current !== id); await this.metadata.write(KEY, value); return true;
    });
  }
  assertClean() {
    return this.serialize(async () => { const value = await this.read(); need(value.pending.length === 0, 'OUTCOME_UNKNOWN'); need(value.dirty === false, 'REFRESH_REQUIRED'); });
  }
  async snapshot(work) {
    need(typeof work === 'function' && !this.blocked, 'SNAPSHOT_RUNNING'); this.queue.blocked = true;
    try {
      await this.serialize(async () => { need((await this.read()).pending.length === 0, 'OUTCOME_UNKNOWN'); });
      const previous = await this.root(), result = await work();
      need(await this.verifySnapshot({ previousRootBinding: previous, currentRootBinding: await this.root(), result }) === true, 'SNAPSHOT_UNVERIFIED');
      await this.serialize(async () => {
        const value = await this.read(); need(value.pending.length === 0, 'OUTCOME_UNKNOWN');
        await this.metadata.write(KEY, { ...value, dirty: false, pending: [] });
      }); return result;
    } finally { this.queue.blocked = false; }
  }
}
module.exports = { WorkspaceRemoteState, KEY };
