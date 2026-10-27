'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), path = require('node:path');
const { fixture, settle } = require('./helpers/main-fixture.cjs');
const Store = require('../src/store.cjs');
const workspaceId = '11111111-1111-4111-8111-111111111111';
function scenario({ onlineOnly = false, firstPreparation = false, holdBackground = false } = {}) {
  let host, factoryOptions, finishBackground;
  const modules = (name, context) => {
    if (name === './workspace-policy.cjs') return { available: () => true };
    if (name === './workspace-legacy-inspector.cjs') return { WorkspaceLegacyInspector: class { inspectProfile() { throw Error('No migration is requested by a normal login.'); } } };
    if (name !== './workspace-native.cjs') return;
    return { createWorkspaceNative: async options => {
      factoryOptions = options;
      const events = context.events, rootProfile = path.join(context.profile, 'workspaces', workspaceId), actorProfile = path.join(context.profile, 'workspace-actors', workspaceId, '202');
      const root = { deviceId: workspaceId, actorId: 101, serverOrigin: 'https://fasakhaninja.com' }, writes = [], caps = [];
      const actor = { metadata: { read: async () => null, write: async (key, value) => { writes.push({ key, value }); } },
        isPrepared: async () => !onlineOnly, connection: async () => root, supportsPrintEffects: () => true,
        control: async value => { if (['pending', 'refresh-begin'].includes(value.action)) throw Error('An actor cannot own root controls.'); return {}; } };
      const state = { read: async () => ({ pending: [], dirty: false }), browse: async () => {}, assertClean: async () => {} };
      const mode = { accepted: new Map(), serialize: async (_name, work) => work(),
        recover: async () => { events.push('workspace-mode-recover'); }, hold: async () => { events.push('workspace-hold'); }, cancelHold: async () => {} };
      const delta = { state: { phase: 'idle' }, running: null, stop() { events.push('workspace-delta-stop'); }, run: async () => ({ unchanged: true }) };
      const services = { remoteState: state, mode, delta, generations: { recover: async () => { events.push('workspace-accepted-recovery'); }, run: async () => ({ unchanged: true }) },
        sync: { state: {}, stop() {}, run: async () => {} }, attempts: { recover: async () => {} }, guard: async () => true,
        connected: { selected: async () => { throw Error('Online authority must not be awaited before the first view.'); }, deferRefresh: async () => false, browse: async () => {}, open: async () => {}, local: async () => {}, reauthenticate: async () => {} },
        connectedReview: { open: async () => {} }, outboxReview: { open: async () => {} }, liveSocial: { busy: () => false, close() {}, open: async () => {} } };
      host = { writes, caps, services, workspace: { physical: { profile: path.join(rootProfile, 'dashboard') },
        control: async value => { events.push('workspace-root-' + value.action); return { commands: [], counts: { pending: 1, conflicts: 0 }, print_state: { prepared: 0, uncertain: 1, unacknowledged: 0 } }; },
        revokeSession: id => caps.push(id) }, updater: new context.Updater(),
        preparation: { status: async () => ({ available: true, prepared: !firstPreparation }), prepare: async () => { events.push('workspace-prepare'); options.relaunch(); return { prepared: true }; } },
        attach() { events.push('workspace-services'); return services; }, selectionStatus: async () => false,
        visible(result) { if (!this.viewReady) { this.viewReady = true; return result.afterView(); } return Promise.resolve(); },
        cancelStartup() { events.push('workspace-owner-cancel'); }, async stop() { events.push('workspace-owner-stop'); },
        login: { cancel() {}, stop: async () => {}, handoff: async result => result,
          admission: async () => {
            const original = await options.legacyBroker.admission();
            if (firstPreparation) return original;
            return { account: { protocol: 2, onlineOnly, id: 'workspace-' + workspaceId + '-202', actorId: 202, role: 'vendor', profile: actorProfile,
                partition: 'persist:go-erp-workspace-' + workspaceId + '-202' }, runtime: actor, actor: { actorId: 202, role: 'vendor' },
              local: !onlineOnly, onlineOnly, origin: onlineOnly ? root.serverOrigin : 'http://127.0.0.1:45123', localToken: onlineOnly ? '' : 'private-group-assets',
              afterView: async () => { events.push('workspace-after-view'); if (holdBackground) await new Promise(resolve => { finishBackground = resolve; }); } };
          } } };
      options.ownRuntime(host); events.push('workspace-owner-registered'); return host;
    } };
  };
  return { modules, get host() { return host; }, get options() { return factoryOptions; }, get finishBackground() { return finishBackground; } };
}

test('actual main dispatches cached B to shared services without v1 identity rebinding or source/queue work before the first view', async t => {
  const s = scenario(), f = await fixture(t, { packaged: true, prepared: true, moduleOverrides: s.modules });
  assert.deepEqual(f.errors, []); assert.equal(f.dashboardOptions.origin, 'http://127.0.0.1:45123');
  assert.equal(f.dashboardOptions.partition, 'persist:go-erp-workspace-' + workspaceId + '-202');
  assert.equal(f.runtimeStarts, 0); assert.equal(f.profileBindings.length, 0); assert.equal(f.dashboardOpens, 1);
  assert.equal(f.events.includes('workspace-accepted-recovery'), true); assert.equal(f.events.includes('workspace-mode-recover'), true);
  assert.equal(f.events.some(e => e.startsWith('workspace-root-pending')), false, 'A local queue or print uncertainty is not an ordinary B login gate.');
  assert.ok(f.events.indexOf('workspace-after-view') > f.events.indexOf('dashboard-open'));
  assert.ok(f.events.indexOf('workspace-owner-registered') < f.events.indexOf('login-form-visible'));
  await f.dashboardOptions.beforeLogout(); assert.equal(s.host.writes[0].key, 'explicit-signout'); assert.deepEqual(s.host.caps, [202]);
  f.app.quit(); await settle(); assert.equal(f.events.includes('workspace-owner-stop'), true); assert.equal(f.store.closeCalls, 1);
  const legacy = new Store(f.file); try { assert.equal(legacy.order(f.order.id).status, 'paid'); assert.equal(legacy.pending()[0].id, f.sale.id); } finally { legacy.close(); }
});

test('an online-only valid account displays its server view while optional current roster work remains unresolved', async t => {
  const s = scenario({ onlineOnly: true, holdBackground: true }), f = await fixture(t, { packaged: true, prepared: true, holdDashboardLoad: true, moduleOverrides: s.modules });
  assert.deepEqual(f.errors, []); assert.equal(f.dashboardOpens, 1); assert.equal(f.loginVisible, false);
  assert.equal(f.dashboardOptions.origin, 'https://fasakhaninja.com'); assert.equal(f.dashboardOptions.localToken, '');
  assert.equal(f.runtimeStarts, 0); assert.equal(f.events.includes('workspace-accepted-recovery'), false);
  assert.equal(typeof s.finishBackground, 'function'); s.finishBackground(); f.finishDashboardLoad(); await settle();
  assert.equal(f.profileBindings.length, 0); f.app.quit(); await settle(); assert.equal(f.store.closeCalls, 1);
});

test('one setup from an ordinary legacy online session uses shared preparation and natural relaunch without switching the old DB local', async t => {
  const s = scenario({ firstPreparation: true }), f = await fixture(t, { packaged: true, moduleOverrides: s.modules });
  assert.deepEqual(f.errors, []);
  const value = await f.dashboardOptions.prepare('https://fasakhaninja.com', 'old-page-csrf-not-authority'); await settle();
  assert.equal(value.prepared, true); assert.equal(f.events.includes('workspace-prepare'), true); assert.equal(f.localSwitches, 0);
  assert.equal(f.app.relaunchCalls, 1); assert.equal(f.app.exited, true); assert.equal(f.events.includes('workspace-owner-stop'), true);
});
