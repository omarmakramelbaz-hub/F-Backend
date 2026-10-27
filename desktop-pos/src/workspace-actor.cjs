'use strict';
const path = require('node:path');
const { GenerationStore } = require('./dashboard-generation.cjs');
const { workspaceBinding } = require('./workspace-prepared.cjs');
const { integer } = require('./workspace-protocol.cjs');
const fail = () => Object.assign(Error('جلسة الحساب لا تطابق مساحة الجهاز.'), { code: 'WORKSPACE_ACTOR_SESSION_INVALID' });
/** Stable actor provider. Promotion changes only private cookie/auth authority;
 * the one physical runtime, root custody and Registry pointer stay independent. */
class WorkspaceActor {
  #workspace; #descriptor; #metadata; #mode; #serverAuthority; #serverAuthenticated;
  constructor({ workspace, descriptor, serverAuthority, serverAuthenticated }) {
    const actorId = descriptor?.actorId, workspaceId = workspace?.registry?.workspaceId;
    if (!integer(actorId) || descriptor.workspaceId !== workspaceId
      || descriptor.profile !== path.join(workspace.registry.root, 'workspace-actors', workspaceId, String(actorId))
      || descriptor.partition !== 'persist:go-erp-workspace-' + workspaceId + '-' + actorId) throw fail();
    this.#workspace = workspace; this.#descriptor = structuredClone(descriptor);
    this.#metadata = new GenerationStore(descriptor.profile, workspace.safeStorage);
    this.#mode = descriptor.onlineOnly === true ? 'server' : 'local';
    if (this.#mode === 'server' && (typeof serverAuthority !== 'function' || typeof serverAuthenticated !== 'function')) throw fail();
    this.#serverAuthority = serverAuthority; this.#serverAuthenticated = serverAuthenticated;
    Object.freeze(this);
  }
  get protocol() { return 2; }
  get onlineOnly() { return this.#mode === 'server'; }
  get actorId() { return this.#descriptor.actorId; }
  get metadata() { return this.#metadata; }
  get workspaceMetadata() { return this.#workspace.metadata; }
  get origin() { return this.#workspace.physical.origin; }
  get token() { return this.#workspace.physical.token; }
  start() { return this.#workspace.start(); }
  connection() { return this.#workspace.connection(); }
  async isPrepared() { return this.#mode === 'local' && await this.#workspace.isPrepared(); }
  authenticated() { return this.#mode === 'local' ? this.#workspace.hasSession(this.actorId) : this.#serverAuthenticated(); }
  offlineAuthenticated() { return this.#workspace.hasSession(this.actorId); }
  async authority() {
    if (this.#mode === 'server') return this.#serverAuthority();
    const member = await this.#workspace.registry.member(this.actorId), active = await this.#workspace.registry.current();
    if (!member || !active) throw fail();
    return { protocol: 2, workspaceId: this.#workspace.registry.workspaceId, actorId: this.actorId, role: member.role,
      manifestRevision: active.manifest.revision, grant: member.grant, workspace: workspaceBinding(active.manifest) };
  }
  session() { return this.#mode === 'local' ? this.#workspace.registry.actorSession(this.actorId) : Promise.resolve(structuredClone(this.#descriptor)); }
  control(value) { return this.#workspace.actorControl(this.actorId, value); }
  printControl(value) { return this.control(value); }
  supportsPrintEffects() { return this.#workspace.physical.supportsPrintEffects(); }
  async promote() {
    if (!await this.offlineAuthenticated()) throw fail();
    const descriptor = await this.#workspace.registry.actorSession(this.actorId);
    if (descriptor.profile !== this.#descriptor.profile || descriptor.partition !== this.#descriptor.partition) throw fail();
    this.#mode = 'local'; return descriptor;
  }
  toJSON() { return { protocol: 2, actorId: this.actorId, onlineOnly: this.onlineOnly }; }
}
module.exports = { WorkspaceActor };
