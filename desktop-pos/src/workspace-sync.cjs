'use strict';
const DashboardSync = require('./dashboard-sync.cjs');
const error = () => Object.assign(Error('تعذر التحقق من ترتيب عمليات الجهاز؛ العمليات محفوظة.'), { code: 'WORKSPACE_SYNC_BINDING_INVALID' });
const hash = value => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
const positive = value => Number.isSafeInteger(value) && value > 0;
function command(value, active) {
  const proof = value?.workspace_authority;
  if (!value || value.device_id !== undefined && value.device_id !== active.manifest.workspace_id || !uuid(value.command_id) || !positive(value.actor_id)
    || !proof || Object.keys(proof).sort().join(',') !== 'actor_id,command_sha256,format,grant,manifest_revision,root_fingerprint,workspace_id'
    || proof.format !== 2 || proof.workspace_id !== active.manifest.workspace_id || proof.root_fingerprint !== active.manifest.root_fingerprint
    || proof.actor_id !== value.actor_id || !positive(proof.manifest_revision) || !hash(proof.grant) || !hash(proof.command_sha256)) throw error();
  // Historical commands keep their ORIGINAL grant. Do not consult/filter by
  // the logged-in actor or today's roster: the server revalidates that proof.
  return value;
}
/**
 * Explicit protocol-2 queue boundary around the original actor-agnostic upload
 * algorithm. One root bearer drains the shared global sequence; B never changes
 * A's command, grant, dependencies or UUID. All business proof checks stay in
 * the original PHP journal plus WorkspaceReconciliation.
 */
class WorkspaceSync extends DashboardSync {
  constructor({ workspace, remote, refresh, effects, ...options }) {
    if (!workspace?.registry || typeof workspace.control !== 'function' || typeof remote !== 'function') throw error();
    const checkedLocal = async value => {
      const active = await workspace.registry.current(); if (!active) throw error();
      const response = await workspace.control(value);
      if (value.action === 'pending') {
        if (!response || !Array.isArray(response.commands) || response.commands.length > 1000 || !response.counts
          || !['pending', 'conflicts'].every(key => Number.isSafeInteger(response.counts[key]) && response.counts[key] >= 0)) throw error();
        for (const item of response.commands) command(item, active);
      }
      return response;
    };
    const checkedRemote = async value => {
      const active = await workspace.registry.current(); if (!active) throw error(); command(value, active);
      return remote(value, active.credential);
    };
    super({ ...options, local: checkedLocal, remote: checkedRemote, refresh, effects, backgroundRefresh: true });
    this.workspace = workspace;
  }
}
module.exports = { WorkspaceSync, command };
