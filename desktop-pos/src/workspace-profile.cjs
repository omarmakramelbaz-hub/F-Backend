'use strict';

const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { GenerationStore } = require('./dashboard-generation.cjs');
const source = require('./dashboard-source.cjs');
const { object, exact, uuid, hash, integer, compare, canonical, digest } = require('./workspace-protocol.cjs');
const workspacePrepared = require('./workspace-prepared.cjs');

const MAX_BYTES = 64 * 1024 ** 2;
const MAX_MANIFEST_BYTES = 16 * 1024 ** 2;
const ROLES = new Set(['admin', 'vendor', 'resturant_owner']);
const revision = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value);
const text = (value, maximum = 320) => typeof value === 'string' && value.length > 0 && value.length <= maximum
  && value === value.trim() && !/[\x00-\x1f\x7f]/.test(value);
const invalid = code => Object.assign(Error('The shared workspace could not be verified.'), { code: 'WORKSPACE_' + code });
const need = (value, code = 'STATE_INVALID') => { if (!value) throw invalid(code); };
const running = signal => need(!signal?.aborted, 'CANCELLED');
const stable = value => [value.dev, value.ino, value.mode, value.nlink, value.size, value.mtimeMs, value.ctimeMs].join(':');
const normalized = value => process.platform === 'win32' ? path.normalize(value).toLowerCase() : path.normalize(value);
const aliases = value => text(value) && value === value.toLowerCase();
const permissionName = value => typeof value === 'string' && value.length > 0 && Buffer.byteLength(value) <= 4096 && !/[\x00-\x1f\x7f]/.test(value);
function freeze(value) {
  if (value && typeof value === 'object' && !Object.isFrozen(value)) { for (const entry of Object.values(value)) freeze(entry); Object.freeze(value); }
  return value;
}
function loginName(value) {
  need(text(typeof value === 'string' ? value.trim() : value), 'LOGIN_NAME_INVALID');
  let name = value.trim().toLowerCase(); if (name.startsWith('0')) name = name.slice(1);
  need(name.length > 0, 'LOGIN_NAME_INVALID'); return name;
}
function sortedSet(value, predicate, maximum) {
  return Array.isArray(value) && value.length <= maximum && value.every(predicate)
    && value.every((entry, index) => index === 0 || compare(value[index - 1], entry) < 0);
}
function manifest(value, workspaceId) {
  need(exact(value, ['format', 'kind', 'workspace_id', 'revision', 'root_fingerprint', 'members', 'roster_sha256', 'signature'])
    && value.format === 2 && value.kind === 'desktop-dashboard-workspace-members'
    && uuid(value.workspace_id) && value.workspace_id === workspaceId && integer(value.revision)
    && hash(value.root_fingerprint) && hash(value.roster_sha256) && hash(value.signature)
    && Array.isArray(value.members) && value.members.length > 0 && value.members.length <= 4096, 'MANIFEST_INVALID');
  const names = new Set(); let previous = 0;
  for (const member of value.members) {
    need(exact(member, ['actor_id', 'role', 'enabled', 'aliases', 'branches', 'permissions', 'password_binding', 'grant'])
      && integer(member.actor_id) && member.actor_id > previous && ROLES.has(member.role) && member.enabled === true
      && sortedSet(member.aliases, aliases, 2) && member.aliases.length > 0
      && sortedSet(member.branches, name => typeof name === 'string' && /^(?:f|gs):[1-9][0-9]{0,18}$/.test(name), 10000)
      && hash(member.password_binding) && hash(member.grant)
      && exact(member.permissions, ['super_admin', 'names', 'roles'])
      && typeof member.permissions.super_admin === 'boolean'
      && sortedSet(member.permissions.names, permissionName, 4096)
      && sortedSet(member.permissions.roles, permissionName, 4096), 'MEMBER_INVALID');
    previous = member.actor_id;
    for (const name of member.aliases) { need(!names.has(name), 'ALIAS_AMBIGUOUS'); names.add(name); }
  }
  need(digest(value.members) === value.roster_sha256 && Buffer.byteLength(canonical(value)) <= MAX_MANIFEST_BYTES, 'ROSTER_INVALID');
  return structuredClone(value);
}
function origin(value) {
  let url; try { url = new URL(value); } catch { throw invalid('CREDENTIAL_INVALID'); }
  need(typeof value === 'string' && url.protocol === 'https:' && url.origin === value && !url.username && !url.password, 'CREDENTIAL_INVALID');
  return value;
}
function record(value, workspaceId) {
  need(exact(value, ['manifest', 'credential', 'candidate']), 'CANDIDATE_INVALID');
  const memberManifest = manifest(value.manifest, workspaceId), credential = value.credential, candidate = value.candidate;
  need(exact(credential, ['workspaceId', 'serverOrigin', 'token', 'enrollmentId']) && credential.workspaceId === workspaceId
    && hash(credential.token) && credential.enrollmentId === memberManifest.root_fingerprint, 'CREDENTIAL_INVALID');
  origin(credential.serverOrigin);
  need(exact(candidate, ['workspaceId', 'database', 'generation', 'snapshotId', 'schemaHash', 'sourceRevision', 'sourceFingerprint', 'sourceSequence', 'manifestRevision', 'rosterSha256', 'prepared', 'receipt'])
    && candidate.workspaceId === workspaceId && typeof candidate.generation === 'string' && /^[a-f0-9]{16}$/.test(candidate.generation)
    && candidate.database === 'fasakhansta_dashboard_stage_' + candidate.generation && uuid(candidate.snapshotId)
    && hash(candidate.schemaHash) && revision(candidate.sourceRevision) && source.valid(candidate.sourceFingerprint)
    && integer(candidate.sourceSequence) && candidate.manifestRevision === memberManifest.revision
    && candidate.rosterSha256 === memberManifest.roster_sha256, 'CANDIDATE_INVALID');
  workspacePrepared.validate(candidate.prepared, candidate.receipt, memberManifest, credential, candidate);
  return structuredClone({ manifest: memberManifest, credential, candidate });
}
const memberFloor = value => ({ revision: value.manifest.revision, digest: digest(value.manifest) });
const sourceFloor = value => ({ sequence: value.candidate.sourceSequence, revision: value.candidate.sourceRevision,
  fingerprint: value.candidate.sourceFingerprint });
function same(left, right) { return canonical(left) === canonical(right); }
function initial(workspaceId) {
  return { format: 2, kind: 'go-erp-workspace-registry', workspaceId, epoch: 0, rootFingerprint: null,
    memberHighWater: { revision: 0, digest: null }, sourceHighWater: { sequence: 0, revision: null, fingerprint: null }, active: null, staged: null };
}
function above(value, state) {
  const member = memberFloor(value), code = sourceFloor(value);
  need(member.revision >= state.memberHighWater.revision
    && (member.revision !== state.memberHighWater.revision || same(member, state.memberHighWater)), 'MEMBERSHIP_ROLLBACK');
  need(code.sequence >= state.sourceHighWater.sequence
    && (code.sequence !== state.sourceHighWater.sequence || same(code, state.sourceHighWater)), 'SOURCE_ROLLBACK');
  need(state.rootFingerprint === null || state.rootFingerprint === value.manifest.root_fingerprint, 'ROOT_CHANGED');
  if (state.active) need(state.active.credential.serverOrigin === value.credential.serverOrigin, 'ORIGIN_CHANGED');
}
function stateValue(value, workspaceId) {
  need(exact(value, ['format', 'kind', 'workspaceId', 'epoch', 'rootFingerprint', 'memberHighWater', 'sourceHighWater', 'active', 'staged'])
    && value.format === 2 && value.kind === 'go-erp-workspace-registry' && value.workspaceId === workspaceId
    && Number.isSafeInteger(value.epoch) && value.epoch >= 0
    && (value.rootFingerprint === null || hash(value.rootFingerprint))
    && exact(value.memberHighWater, ['revision', 'digest'])
    && Number.isSafeInteger(value.memberHighWater.revision) && value.memberHighWater.revision >= 0
    && (value.memberHighWater.revision === 0 ? value.memberHighWater.digest === null : hash(value.memberHighWater.digest))
    && exact(value.sourceHighWater, ['sequence', 'revision', 'fingerprint'])
    && Number.isSafeInteger(value.sourceHighWater.sequence) && value.sourceHighWater.sequence >= 0
    && (value.sourceHighWater.sequence === 0 ? value.sourceHighWater.revision === null && value.sourceHighWater.fingerprint === null
      : revision(value.sourceHighWater.revision) && source.valid(value.sourceHighWater.fingerprint)), 'STATE_INVALID');
  if (value.active !== null) {
    record(value.active, workspaceId);
    need(value.active.manifest.root_fingerprint === value.rootFingerprint
      && value.active.manifest.revision <= value.memberHighWater.revision
      && value.active.candidate.sourceSequence <= value.sourceHighWater.sequence, 'STATE_INVALID');
    if (value.active.manifest.revision === value.memberHighWater.revision) need(same(memberFloor(value.active), value.memberHighWater), 'STATE_INVALID');
    if (value.active.candidate.sourceSequence === value.sourceHighWater.sequence) need(same(sourceFloor(value.active), value.sourceHighWater), 'STATE_INVALID');
  }
  if (value.staged !== null) {
    need(exact(value.staged, ['id', 'record', 'digest']) && uuid(value.staged.id) && hash(value.staged.digest), 'STATE_INVALID');
    record(value.staged.record, workspaceId); above(value.staged.record, value);
    need(digest(value.staged.record) === value.staged.digest, 'STATE_INVALID');
  }
  need(Buffer.byteLength(canonical(value)) <= MAX_BYTES, 'STATE_TOO_LARGE'); return value;
}

const writers = new Map();
/**
 * Native-only encrypted metadata foundation. It is deliberately not wired to
 * the app. Callers must own the original primary-instance lock and global
 * accepted-work/database fence. No signature key is supplied by a renderer:
 * verifyAuthority and verifyCandidate are trusted native verification hooks.
 *
 * stage({manifest, credential, candidate}, {signal}) keeps a non-active ticket.
 * The candidate binds the physical workspace, staged DB/generation, snapshot,
 * schema/source and both publication sequence and member revision/digest. The
 * complete root prepared descriptor and independently verified import receipt
 * move in that same encrypted record; no second prepared-file pointer exists.
 * activate(ticket, {signal, verifyCandidate}) requires the original import,
 * source and current-data/fence verification to have succeeded. A callback's
 * true result is a native trust boundary; it must never be backed by an IPC
 * boolean or renderer-provided proof. Failed/cancelled commit compensation
 * restores the previous active pointer without lowering accepted high waters.
 *
 * current/member/findActor/actorSession are private native APIs. Only
 * publicState may be forwarded to a renderer; it contains no membership,
 * credential, password fingerprint or per-actor grant material.
 */
class WorkspaceRegistry {
  constructor({ root, workspaceId, safeStorage, verifyAuthority }) {
    need(typeof root === 'string' && path.isAbsolute(root) && path.resolve(root) === root
      && root !== path.parse(root).root && uuid(workspaceId) && typeof verifyAuthority === 'function', 'CONFIGURATION_INVALID');
    this.root = root; this.workspaceId = workspaceId; this.verifyAuthority = verifyAuthority;
    this.metadata = new GenerationStore(root, safeStorage); this.file = path.join(root, 'workspace-registry.enc');
    this.workspaceProfile = path.join(root, 'workspaces', workspaceId); this.cached = null;
  }
  serialize(work) {
    const key = normalized(this.root), previous = writers.get(key) || Promise.resolve(), task = previous.then(work), tail = task.catch(() => {});
    writers.set(key, tail); tail.finally(() => { if (writers.get(key) === tail) writers.delete(key); }); return task;
  }
  async directory(target, create = false) {
    need(typeof target === 'string' && path.isAbsolute(target) && path.resolve(target) === target
      && (target === this.root || target.startsWith(this.root + path.sep)), 'PATH_INVALID');
    let cursor = path.parse(target).root;
    for (const component of target.slice(cursor.length).split(path.sep).filter(Boolean)) {
      cursor = path.join(cursor, component); let stat;
      try { stat = await fs.lstat(cursor); }
      catch (caught) {
        if (caught.code !== 'ENOENT' || !create || cursor === this.root || !cursor.startsWith(this.root + path.sep)) throw invalid('PATH_INVALID');
        try { await fs.mkdir(cursor, { mode: 0o700 }); } catch (error) { if (error.code !== 'EEXIST') throw error; }
        stat = await fs.lstat(cursor);
      }
      need(stat.isDirectory() && !stat.isSymbolicLink(), 'PATH_INVALID');
    }
    need(normalized(await fs.realpath(target)) === normalized(target), 'PATH_INVALID'); return target;
  }
  async fileState() {
    await this.directory(this.root);
    try {
      const info = await fs.lstat(this.file);
      need(info.isFile() && !info.isSymbolicLink() && info.nlink === 1 && info.size > 0 && info.size <= MAX_BYTES, 'FILE_INVALID');
      return stable(info);
    } catch (caught) { if (caught.code === 'ENOENT') return null; throw caught; }
  }
  async read(force = false) {
    const before = await this.fileState();
    if (!force && this.cached?.identity === before) return this.cached.value;
    let value;
    try { value = before === null ? initial(this.workspaceId) : stateValue(await this.metadata.read('workspace-registry'), this.workspaceId); }
    catch (caught) { if (caught?.code?.startsWith('WORKSPACE_')) throw caught; throw invalid('STATE_INVALID'); }
    need(before === await this.fileState(), 'STATE_CHANGED');
    freeze(value);
    this.cached = { identity: before, value, members: new Map((value.active?.manifest.members || []).map(member => [member.actor_id, member])),
      aliases: new Map((value.active?.manifest.members || []).flatMap(member => member.aliases.map(name => [name, member.actor_id]))) };
    return value;
  }
  async commit(previous, next, signal) {
    stateValue(next, this.workspaceId); running(signal);
    try {
      await this.metadata.write('workspace-registry', next); running(signal);
      need(same(await this.read(true), next), 'STATE_CHANGED');
    } catch (caught) {
      let current; try { current = await this.read(true); } catch { throw invalid('RECOVERY_REQUIRED'); }
      if (same(current, next)) {
        const recovery = structuredClone(previous); recovery.epoch = next.epoch + 1;
        recovery.rootFingerprint ||= next.rootFingerprint;
        if (next.memberHighWater.revision > recovery.memberHighWater.revision) recovery.memberHighWater = next.memberHighWater;
        if (next.sourceHighWater.sequence > recovery.sourceHighWater.sequence) recovery.sourceHighWater = next.sourceHighWater;
        try { await this.metadata.write('workspace-registry', recovery); need(same(await this.read(true), recovery), 'RECOVERY_REQUIRED'); }
        catch { throw invalid('RECOVERY_REQUIRED'); }
      } else if (!same(current, previous)) throw invalid('RECOVERY_REQUIRED');
      throw caught;
    }
  }
  ticket(state) { return { format: 2, workspaceId: this.workspaceId, stageId: state.staged.id, epoch: state.epoch, digest: state.staged.digest }; }
  staged(state, ticket) {
    need(exact(ticket, ['format', 'workspaceId', 'stageId', 'epoch', 'digest']) && ticket.format === 2 && ticket.workspaceId === this.workspaceId
      && state.staged && ticket.stageId === state.staged.id && ticket.epoch === state.epoch && ticket.digest === state.staged.digest, 'STAGE_STALE');
  }
  async stage(value, { signal } = {}) {
    const checked = record(value, this.workspaceId);
    return this.serialize(async () => {
      running(signal); const previous = await this.read(true); above(checked, previous);
      need(await this.verifyAuthority(structuredClone(checked), { signal }) === true, 'AUTHORITY_REJECTED'); running(signal);
      need(same(await this.read(true), previous), 'STATE_CHANGED');
      if (previous.staged) { need(previous.staged.digest === digest(checked), 'STAGE_BUSY'); return this.ticket(previous); }
      const next = { ...previous, epoch: previous.epoch + 1, rootFingerprint: checked.manifest.root_fingerprint,
        staged: { id: crypto.randomUUID(), record: checked, digest: digest(checked) } };
      await this.commit(previous, next, signal); return this.ticket(next);
    });
  }
  async activate(ticket, { signal, verifyCandidate } = {}) {
    need(typeof verifyCandidate === 'function', 'VERIFIER_REQUIRED');
    return this.serialize(async () => {
      running(signal); const previous = await this.read(true); this.staged(previous, ticket); const active = previous.staged.record;
      need(await verifyCandidate(structuredClone(active), { signal }) === true, 'CANDIDATE_REJECTED'); running(signal);
      need(same(await this.read(true), previous), 'STATE_CHANGED');
      const next = { ...previous, epoch: previous.epoch + 1, active, staged: null,
        memberHighWater: memberFloor(active), sourceHighWater: sourceFloor(active) };
      await this.commit(previous, next, signal); return structuredClone(active.candidate);
    });
  }
  async rollback(ticket, { signal } = {}) {
    return this.serialize(async () => {
      running(signal); const previous = await this.read(true); this.staged(previous, ticket);
      await this.commit(previous, { ...previous, epoch: previous.epoch + 1, staged: null }, signal); return true;
    });
  }
  async current() { const state = await this.read(); return state.active ? structuredClone(state.active) : null; }
  async member(actorId) { need(integer(actorId), 'ACTOR_INVALID'); await this.read(); const member = this.cached.members.get(actorId); return member ? structuredClone(member) : null; }
  async findActor(input) { const name = loginName(input); await this.read(); const actor = this.cached.aliases.get(name); return actor ? structuredClone(this.cached.members.get(actor)) : null; }
  async actorSession(actorId, { create = true } = {}) {
    need(integer(actorId) && typeof create === 'boolean', 'ACTOR_INVALID'); const state = await this.read();
    const member = this.cached.members.get(actorId); need(state.active && member, 'ACTOR_DENIED');
    const directory = path.join(this.root, 'workspace-actors', this.workspaceId, String(actorId));
    await this.directory(this.workspaceProfile, create); await this.directory(directory, create);
    need((await this.read()).epoch === state.epoch, 'STATE_CHANGED');
    return { protocol: 2, workspaceId: this.workspaceId, actorId, role: member.role, serverOrigin: state.active.credential.serverOrigin,
      profile: directory, workspaceProfile: this.workspaceProfile, database: state.active.candidate.database,
      generation: state.active.candidate.generation, partition: 'persist:go-erp-workspace-' + this.workspaceId + '-' + actorId };
  }
  async publicState() {
    const state = await this.read(); return { protocol: 2, prepared: state.active !== null, staging: state.staged !== null,
      members: state.active?.manifest.members.length || 0, revision: state.active?.manifest.revision || 0 };
  }
}

module.exports = { WorkspaceRegistry, manifest, record, canonical, digest, loginName, MAX_BYTES };
