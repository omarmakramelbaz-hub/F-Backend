'use strict';
// Executable DESIGN specification. No application import, database, network or
// printer is used. Exhaustive exploration checks a small asynchronous protocol;
// it is deliberately not a mock that asserts a production method's own output.
const assert = require('node:assert/strict');
const { test } = require('node:test');
const crypto = require('node:crypto');

const DEVICE = 'e4ac46ae-f518-41e1-bd7f-dc8977e3be77';
const COMMAND = '56a7306f-3339-4428-a0ee-155a2b008ab9';
const ENROLLMENT = 'enrollment-A';
const INVOCATION = 'df368fdd-5303-4ecf-b4bc-e43a23655f01';
const digest = value => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const clone = value => JSON.parse(JSON.stringify(value));
function model(kind = 'phone_kitchen') {
const profiles = {
  phone_kitchen: { refs: { ticket: 41, 'kitchen.primary': 57, 'print_job.primary': 63 },
    mappings: [['ticket', 41, 1041], ['kitchen.primary', 57, 1057], ['print_job.primary', 63, 1063]] },
  shift_report: { refs: { shift: 71, 'shift_print_job.primary': 73 },
    mappings: [['shift', 71, 1071], ['shift_print_job.primary', 73, 1073]] },
  sale_receipt: { refs: { receipt: 81, 'sale_till_entry.primary': 83 },
    mappings: [['receipt', 81, 1081], ['sale_till_entry.primary', 83, 1083]] },
};
assert.ok(profiles[kind]);
const descriptor = Object.freeze({ device: DEVICE, command: COMMAND, enrollment: ENROLLMENT,
  actor: 10, branch: 'f:100', kind, slot: 'primary', revision: 3,
  refs: profiles[kind].refs, snapshot: 'immutable revision 3' });
const EFFECT = digest(descriptor);
const INPUT = Object.freeze({ device: DEVICE, actor: 10, branch: 'f:100', enrollment: ENROLLMENT,
  command: COMMAND, effect: EFFECT, invocation: INVOCATION, generation: 0 });

function initial() {
  return { running: true, created: false, businessAck: false, effect: null, server: null,
    generation: 0, ledger: 'stable private ledger', permit: false, callbackPending: false,
    localCalls: 0, centralCalls: 0, crashCount: 0, edit: false, currentKitchen: 57,
    enrollment: 'valid', revocationUsed: false, replyLost: false, grantValid: true };
}
function authorized(state, input, server = false, currentGeneration = false) {
  // The caller can keep the original cached device object. Authorization reads
  // the model's CURRENT persisted enrollment, never that cached object.
  return (!server || state.enrollment === 'valid') && (!currentGeneration || input.generation === state.generation) && input.device === DEVICE
    && input.actor === 10 && input.branch === 'f:100' && input.enrollment === ENROLLMENT
    && input.command === COMMAND && input.effect === EFFECT;
}
function outcomeReceipt(state) {
  return { ...INPUT, kind: descriptor.kind, revision: descriptor.revision,
    descriptorHash: EFFECT, outcome: state.effect?.outcome ?? null,
    mappings: state.server?.mappings ? clone(state.server.mappings) : null };
}
function expectedMappings() {
  return profiles[kind].mappings.map(([entity, local_id, server_id]) => ({ entity, local_id, server_id }));
}

// A transition is atomic only where the design requires an InnoDB transaction
// or a native one-use handle. Replies, invocations and callbacks are separate.
function step(original, action, input = null, faults = {}) {
  input ??= { ...INPUT, generation: original.generation };
  const s = clone(original), e = s.effect;
  const reject = () => null;
  switch (action) {
    case 'generate':
      if (!s.running || s.created) return reject();
      s.created = true;
      s.effect = { descriptor: clone(descriptor), originGeneration: s.generation,
        state: 'queued', burnt: false, firstGrantConsumed: false, outcome: null, serverAck: false };
      break;
    case 'later-edit':
      if (!s.running || !s.created || s.edit) return reject();
      s.edit = true; s.currentKitchen = 99; break;
    case 'claim':
      if (!s.running || !authorized(s, input, false, true) || e?.state !== 'queued'
          || (kind === 'sale_receipt' && !s.grantValid)) return reject();
      e.state = 'claimed'; break;
    case 'burn':
    case 'burn-lost-reply':
      if (!s.running || !authorized(s, input, false, true) || e?.state !== 'claimed' || e.burnt
          || (kind === 'sale_receipt' && !s.grantValid)) return reject();
      // An accepted burn may return exactly one live native handle. A lost reply
      // persists the same burn but cannot return/recover that handle later.
      e.burnt = true; e.state = 'burnt';
      if (kind === 'sale_receipt') e.firstGrantConsumed = true;
      s.permit = action === 'burn'; s.replyLost = action === 'burn-lost-reply'; break;
    case 'invoke-native':
      if (!s.running || !authorized(s, input, false, true) || e?.state !== 'burnt' || !s.permit) return reject();
      s.permit = false; s.localCalls++; s.callbackPending = true; break;
    case 'callback-accepted':
    case 'callback-ambiguous':
      if (!s.running || !e || !s.callbackPending || input.invocation !== INVOCATION
          || !authorized(s, input)) return reject();
      s.callbackPending = false;
      e.outcome = action === 'callback-accepted' ? 'invoked' : 'review';
      e.state = e.outcome; break;
    case 'sync-business':
      if (!s.running || !e || !authorized(s, input, true)) return reject();
      if (s.server) return s;
      s.server = { owner: DEVICE, effect: EFFECT, command: COMMAND,
        // Fault injection represents the tempting unsafe rule: only suppress
        // central enqueue if the local completion has already arrived.
        queue: faults.waitForCompletion && e.outcome !== 'invoked' ? 'pending' : 'suppressed',
        mappings: expectedMappings(), receipt: null };
      if (faults.inferLatestKitchen && kind === 'phone_kitchen') s.server.mappings[1].local_id = s.currentKitchen;
      break;
    case 'ack-business':
      if (!s.running || !s.server || s.businessAck) return reject();
      s.businessAck = true; break;
    case 'send-outcome':
      if (!s.running || !e?.outcome || !s.server
          || !authorized(s, input, true) || input.invocation !== INVOCATION) return reject();
      if (s.server.receipt) return digest(s.server.receipt) === digest(outcomeReceipt(s)) ? s : reject();
      s.server.receipt = outcomeReceipt(s); break;
    case 'ack-outcome':
      if (!s.running || !e || !s.server?.receipt
          || !authorized(s, input) || input.invocation !== INVOCATION) return reject();
      if (digest(s.server.receipt) !== digest(outcomeReceipt(s))) return reject();
      e.serverAck = true; break;
    case 'central-invoke':
      if (s.server?.queue !== 'pending') return reject();
      s.server.queue = 'invoked'; s.centralCalls++; break;
    case 'crash':
      if (!s.running || s.crashCount) return reject();
      s.running = false; s.crashCount++; s.permit = false; s.callbackPending = false; break;
    case 'restart':
      if (s.running) return reject();
      s.running = true;
      if (e && ['claimed', 'burnt'].includes(e.state)) {
        if (faults.restorePermit && e.burnt) {
          e.state = 'burnt'; s.permit = true;
        } else { e.state = 'review'; e.outcome = 'review'; }
      }
      break;
    case 'refresh':
      if (!s.running || !s.businessAck || s.generation || s.permit) return reject();
      s.generation = 1;
      // Late native callback and outcome delivery address the permanent ledger,
      // not an old HTTP port or a row ID in the replacement generation.
      if (e && ['claimed', 'burnt'].includes(e.state)) {
        e.state = 'review';
        if (!s.callbackPending) e.outcome = 'review';
      }
      if (faults.eraseLedger) s.effect = null;
      break;
    case 'expire-first-grant':
      if (kind !== 'sale_receipt' || !s.created || !s.grantValid) return reject();
      s.grantValid = false; break;
    case 'revoke':
    case 'reassign-actor':
    case 'remove-branch':
      if (s.revocationUsed) return reject();
      s.revocationUsed = true;
      s.enrollment = action; break;
    default: throw Error('Unknown model action: ' + action);
  }
  return s;
}
const ACTIONS = ['generate', 'later-edit', 'claim', 'burn', 'burn-lost-reply', 'invoke-native',
  'callback-accepted', 'callback-ambiguous', 'sync-business', 'ack-business', 'send-outcome',
  'ack-outcome', 'central-invoke', 'crash', 'restart', 'refresh', 'revoke', 'reassign-actor', 'remove-branch', 'expire-first-grant'];

// These assertions use the external invocation audit and original identity,
// independently of the transition's declared state or success marker.
function invariant(s, { allowFaultyQueue = false } = {}) {
  assert.ok(s.localCalls + s.centralCalls <= 1, 'One originating effect invoked more than once');
  if (s.created) {
    assert.ok(s.effect, 'Refresh erased an accepted effect');
    assert.equal(s.ledger, 'stable private ledger');
    assert.equal(digest(s.effect.descriptor), EFFECT, 'Origin receipt identity/revision mutated');
    assert.equal(s.effect.originGeneration, 0, 'Refresh rewrote originating generation');
  }
  if (s.server) {
    assert.equal(s.server.owner, DEVICE);
    assert.equal(s.server.effect, EFFECT);
    assert.deepEqual(s.server.mappings, expectedMappings(), 'Typed original references were rebound');
    if (!allowFaultyQueue) assert.equal(s.server.queue, 'suppressed', 'Device-owned job became centrally printable');
  }
  if (s.permit) assert.ok(s.effect?.burnt && s.effect.state === 'burnt' && s.running);
  if (s.localCalls) assert.equal(s.effect?.burnt, true, 'Invocation preceded durable burn');
  if (s.localCalls && kind === 'sale_receipt') assert.equal(s.effect?.firstGrantConsumed, true, 'Invocation preceded first receipt grant consumption');
  if (s.effect?.outcome === 'invoked') assert.equal(s.localCalls, 1, 'Success was invented without invocation');
  if (s.effect?.serverAck) assert.ok(s.server?.receipt, 'Acknowledgement has no exact durable receipt');
  if (s.replyLost && !s.localCalls) assert.notEqual(s.effect?.outcome, 'invoked', 'Lost grant became fake success');
}
function trace(records, index, last) {
  const result = [last];
  while (index >= 0) { const r = records[index]; if (r.action) result.push(r.action); index = r.parent; }
  return result.reverse();
}
function explore({ faults = {}, invariantOptions = {}, maxDepth = 22, stopOnViolation = false } = {}) {
  const records = [{ s: initial(), parent: -1, action: null, depth: 0 }], seen = new Set([JSON.stringify(initial())]);
  let transitions = 0, maxObservedDepth = 0;
  for (let cursor = 0; cursor < records.length; cursor++) {
    const record = records[cursor];
    if (record.depth >= maxDepth) continue;
    for (const action of ACTIONS) {
      const next = step(record.s, action, null, faults);
      if (!next) continue;
      transitions++;
      try { invariant(next, invariantOptions); }
      catch (error) {
        const violation = { message: error.message.split('\n')[0], trace: trace(records, cursor, action), state: next };
        if (stopOnViolation) return { violation, states: records.length, transitions };
        error.message += '\nInterleaving: ' + violation.trace.join(' -> '); throw error;
      }
      const key = JSON.stringify(next);
      if (seen.has(key)) continue;
      seen.add(key);
      maxObservedDepth = Math.max(maxObservedDepth, record.depth + 1);
      records.push({ s: next, parent: cursor, action, depth: record.depth + 1 });
      assert.ok(records.length <= 250000, 'State exploration exceeded its declared finite bound');
    }
  }
  // Search saturation, rather than a silent depth cut, is part of the evidence.
  assert.ok(maxObservedDepth < maxDepth, 'Increase depth: exploration did not reach a fixed point');
  return { states: records.length, transitions, maxObservedDepth };
}
function run(actions, faults = {}) {
  let s = initial();
  for (const action of actions) { const next = step(s, action, null, faults); assert.ok(next, 'Disabled action: ' + action); s = next; }
  return s;
}
return { initial, step, invariant, explore, run, INPUT, descriptor, outcomeReceipt };
}
const { step, invariant, explore, run, INPUT, outcomeReceipt } = model();

test('all enabled bounded interleavings preserve one invocation, immutable origin and durable refresh ownership', t => {
  for (const kind of ['phone_kitchen', 'shift_report', 'sale_receipt']) {
    const result = model(kind).explore();
    t.diagnostic(JSON.stringify({ kind, ...result }));
    assert.ok(result.states > 1000);
    assert.ok(result.transitions > result.states);
  }
});

test('both sync-before-print and print-before-sync suppress central enqueue independently of outcome arrival', () => {
  for (const actions of [
    ['generate', 'sync-business', 'ack-business', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'send-outcome', 'ack-outcome'],
    ['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'sync-business', 'ack-business', 'send-outcome', 'ack-outcome'],
  ]) {
    const s = run(actions); invariant(s);
    assert.equal(s.localCalls, 1); assert.equal(s.centralCalls, 0); assert.equal(s.effect.serverAck, true);
  }
});

test('queued, uncertain and completed effects survive refresh; a callback/receipt can arrive after refresh', () => {
  const queued = run(['generate', 'sync-business', 'ack-business', 'refresh', 'claim', 'burn', 'invoke-native', 'callback-accepted']);
  const late = run(['generate', 'claim', 'burn', 'invoke-native', 'sync-business', 'ack-business', 'refresh', 'callback-accepted', 'send-outcome', 'ack-outcome']);
  const uncertain = run(['generate', 'claim', 'burn-lost-reply', 'sync-business', 'ack-business', 'refresh', 'send-outcome', 'ack-outcome']);
  for (const s of [queued, late, uncertain]) { invariant(s); assert.equal(s.generation, 1); }
  assert.equal(late.effect.serverAck, true); assert.equal(late.localCalls, 1);
  assert.equal(uncertain.localCalls, 0); assert.equal(uncertain.effect.state, 'review');
  assert.equal(step(uncertain, 'burn'), null);
});

test('crash at claim, before invocation, or after invocation never recreates a native permit or fakes acceptance', () => {
  for (const prefix of [
    ['generate', 'claim'],
    ['generate', 'claim', 'burn'],
    ['generate', 'claim', 'burn-lost-reply'],
    ['generate', 'claim', 'burn', 'invoke-native'],
  ]) {
    const s = run([...prefix, 'crash', 'restart']); invariant(s);
    assert.equal(s.effect.state, 'review'); assert.equal(s.permit, false);
    assert.equal(step(s, 'claim'), null); assert.equal(step(s, 'burn'), null); assert.equal(step(s, 'invoke-native'), null);
  }
  const accepted = run(['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'crash', 'restart']);
  assert.equal(accepted.effect.state, 'invoked'); assert.equal(step(accepted, 'invoke-native'), null);
});

test('foreign/stale identities cannot claim, burn, complete or acknowledge, and current locked server enrollment wins', () => {
  const claimed = run(['generate', 'claim']);
  const pending = run(['generate', 'claim', 'burn', 'invoke-native']);
  const delivered = run(['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'sync-business', 'send-outcome']);
  for (const field of ['device', 'actor', 'branch', 'enrollment', 'command', 'effect']) {
    const foreign = { ...INPUT, [field]: field === 'actor' ? 11 : 'foreign' };
    assert.equal(step(run(['generate']), 'claim', foreign), null, field);
    assert.equal(step(claimed, 'burn', foreign), null, field);
    assert.equal(step(pending, 'callback-accepted', foreign), null, field);
    assert.equal(step(delivered, 'ack-outcome', foreign), null, field);
  }
  assert.equal(step(pending, 'callback-accepted', { ...INPUT, invocation: 'stale invocation' }), null);
  const refreshed = run(['generate', 'sync-business', 'ack-business', 'refresh']);
  assert.equal(step(refreshed, 'claim', INPUT), null, 'Old page generation cannot claim after refresh');
  assert.ok(step(refreshed, 'claim'), 'Current page generation can claim retained queued work');
  const late = run(['generate', 'claim', 'burn', 'invoke-native', 'sync-business', 'ack-business', 'refresh']);
  assert.ok(step(late, 'callback-accepted', INPUT), 'Existing invocation completion is permanent, not a new stale-page grant');
  for (const change of ['revoke', 'reassign-actor', 'remove-branch']) {
    // INPUT still represents the previously loaded valid enrollment/device object.
    const stale = run(['generate', change]);
    assert.equal(step(stale, 'sync-business', INPUT), null);
    const staleOutcome = run(['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'sync-business', change]);
    assert.equal(step(staleOutcome, 'send-outcome', INPUT), null);
    assert.ok(staleOutcome.effect); assert.equal(staleOutcome.effect.serverAck, false);
  }
});

test('later edits do not rebind original kitchen references, and a shift receipt cannot masquerade as a kitchen outcome', () => {
  const s = run(['generate', 'later-edit', 'sync-business']); invariant(s);
  assert.equal(s.currentKitchen, 99); assert.equal(s.effect.descriptor.refs['kitchen.primary'], 57);
  const delivered = run(['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'sync-business', 'send-outcome']);
  delivered.server.receipt.kind = 'shift_report';
  assert.equal(step(delivered, 'ack-outcome'), null);
  delivered.server.receipt = outcomeReceipt(delivered);
  delivered.server.receipt.mappings[1].entity = 'shift';
  assert.equal(step(delivered, 'ack-outcome'), null);
});

test('first receipt grant expires without invocation, burns atomically, and has no usable second central grant', () => {
  const receipt = model('sale_receipt');
  const expired = receipt.run(['generate', 'claim', 'expire-first-grant']);
  assert.equal(receipt.step(expired, 'burn'), null);
  assert.equal(expired.effect.firstGrantConsumed, false); assert.equal(expired.localCalls, 0);
  for (const actions of [
    ['generate', 'sync-business', 'ack-business', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'send-outcome', 'ack-outcome'],
    ['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted', 'sync-business', 'ack-business', 'refresh', 'send-outcome', 'ack-outcome'],
  ]) {
    const s = receipt.run(actions); receipt.invariant(s);
    assert.equal(s.effect.firstGrantConsumed, true); assert.equal(s.server.queue, 'suppressed');
    assert.equal(receipt.step(s, 'central-invoke'), null); assert.equal(receipt.step(s, 'burn'), null);
  }
  const lost = receipt.run(['generate', 'claim', 'burn-lost-reply', 'crash', 'restart']);
  assert.equal(lost.effect.firstGrantConsumed, true); assert.equal(lost.effect.state, 'review');
  assert.equal(lost.localCalls, 0); assert.equal(receipt.step(lost, 'burn'), null);
});

test('lost business and outcome replies can be retried after refresh without replaying a physical call', () => {
  for (const kind of ['phone_kitchen', 'shift_report', 'sale_receipt']) {
    const protocol = model(kind);
    let s = protocol.run(['generate', 'claim', 'burn', 'invoke-native', 'callback-accepted']);
    assert.equal(protocol.step(s, 'send-outcome'), null, 'No server mapping before business commit');
    for (const action of ['sync-business', 'sync-business', 'ack-business', 'send-outcome', 'refresh', 'send-outcome', 'ack-outcome', 'ack-outcome']) {
      s = protocol.step(s, action); assert.ok(s, kind + ' ' + action); protocol.invariant(s);
    }
    assert.equal(s.localCalls, 1); assert.equal(s.effect.serverAck, true);
    assert.equal(protocol.step(s, 'invoke-native'), null);
  }
});

test('the explorer finds actual duplicate-invocation counterexamples for completion-dependent suppression and restored permits', t => {
  for (const faults of [{ waitForCompletion: true }, { restorePermit: true }]) {
    const result = explore({ faults, invariantOptions: { allowFaultyQueue: true }, stopOnViolation: true });
    assert.ok(result.violation); assert.match(result.violation.message, /invoked more than once/);
    assert.equal(result.violation.state.localCalls + result.violation.state.centralCalls, 2);
    t.diagnostic(JSON.stringify({ faults, trace: result.violation.trace }));
  }
});

test('the explorer detects refresh loss and mutable-latest-kitchen mapping as separate protocol defects', t => {
  for (const [faults, expected] of [[{ eraseLedger: true }, /erased an accepted effect/], [{ inferLatestKitchen: true }, /Typed original references/]]) {
    const result = explore({ faults, stopOnViolation: true });
    assert.ok(result.violation); assert.match(result.violation.message, expected);
    t.diagnostic(JSON.stringify({ faults, trace: result.violation.trace }));
  }
});
