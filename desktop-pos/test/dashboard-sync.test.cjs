const test = require('node:test');
const assert = require('node:assert/strict');
const DashboardSync = require('../src/dashboard-sync.cjs');

function fixture(remote) {
  const queue = [{ command_id: 'first', payload: { values: { amount: '10.00' } } }, { command_id: 'second' }];
  const receipts = new Map(); const attempts = [];
  const local = async request => {
    if (request.action === 'acknowledge') {
      assert.equal(request.receipt.command_id, request.command_id);
      assert.equal(request.receipt.committed, true);
      receipts.set(request.command_id, request.receipt); queue.shift();
    }
    if (request.action === 'failed') attempts.push(request);
    return { commands: queue.slice(0, 1), counts: { pending: queue.length, conflicts: 0, acknowledged: receipts.size } };
  };
  return { queue, receipts, attempts, sync: new DashboardSync({ local, remote }) };
}

test('a lost reply reuses the identical command and waits for its local confirmation before the next', async () => {
  const committed = new Map(); let lost = true; const calls = [];
  const f = fixture(async command => {
    calls.push(command.command_id);
    if (!committed.has(command.command_id)) committed.set(command.command_id, { command_id: command.command_id, committed: true });
    if (lost) { lost = false; throw Error('lost connection after commit'); }
    return committed.get(command.command_id);
  });
  await f.sync.run();
  assert.equal(f.queue.length, 2); assert.equal(f.attempts[0].conflict, false);
  await f.sync.run();
  assert.deepEqual(calls, ['first', 'first', 'second']); assert.equal(committed.size, 2);
  assert.equal(f.queue.length, 0); assert.equal(f.sync.state.acknowledged, 2);
});

test('a revision conflict preserves the blocked operation and sends no later command', async () => {
  const calls = []; const f = fixture(async command => { calls.push(command.command_id); const e = Error('revision changed'); e.status = 409; throw e; });
  await f.sync.run();
  assert.deepEqual(calls, ['first']); assert.equal(f.queue.length, 2); assert.equal(f.attempts[0].conflict, true);
});

test('overlapping ticks share one accepted run', async () => {
  let release; let calls = 0;
  const gate = new Promise(resolve => { release = resolve; });
  const f = fixture(async command => { calls++; await gate; return { command_id: command.command_id, committed: true }; });
  const first = f.sync.run(); const second = f.sync.run();
  assert.equal(first, second); release(); await first;
  assert.equal(calls, 2); assert.equal(f.queue.length, 0);
});

test('new local work created during a cycle waits for the next cycle without changing its UUID', async () => {
  const queue = [{ command_id: 'already-waiting' }], sends = []; let added = false;
  const sync = new DashboardSync({
    local: async request => {
      if (request.action === 'acknowledge') {
        assert.equal(request.command_id, queue[0].command_id); queue.shift();
        if (!added) { queue.push({ command_id: 'created-during-sync' }); added = true; }
      }
      return { commands: queue.slice(0, 1), counts: { pending: queue.length, conflicts: 0 } };
    }, remote: async command => { sends.push(command.command_id); return { command_id: command.command_id, committed: true }; }
  });
  await sync.run(); assert.deepEqual(sends, ['already-waiting']);
  assert.deepEqual(queue, [{ command_id: 'created-during-sync' }]); assert.equal(sync.state.pending, 1);
  await sync.run(); assert.deepEqual(sends, ['already-waiting', 'created-during-sync']); assert.equal(queue.length, 0);
});

test('closing during a committed remote request retains its unconfirmed UUID for the next launch', async () => {
  let release; let entered;
  const started = new Promise(resolve => { entered = resolve; });
  const gate = new Promise(resolve => { release = resolve; });
  const f = fixture(async command => { entered(); await gate; return { command_id: command.command_id, committed: true }; });
  const running = f.sync.run(); await started; f.sync.stop(); release(); await running;
  assert.equal(f.queue.length, 2); assert.equal(f.receipts.size, 0); assert.equal(f.attempts.length, 0);
});

test('failure to persist a remote acknowledgement cannot discard the operation', async () => {
  let pending = true; let sends = 0;
  const sync = new DashboardSync({
    local: async request => {
      if (request.action === 'acknowledge') throw Error('local disk error');
      return { commands: pending ? [{ command_id: 'kept' }] : [], counts: { pending: 1 } };
    }, remote: async command => { sends++; return { command_id: command.command_id, committed: true }; }
  });
  await sync.run(); assert.equal(sends, 1); assert.equal(pending, true); assert.equal(sync.state.error, 'local disk error');
});

test('refresh follows durable confirmation, waits for all conflicts and is throttled on idle ticks',async()=>{
  let pending=1,conflicts=0,refreshes=0,now=1000;const events=[];
  const sync=new DashboardSync({now:()=>now,refreshInterval:60000,
    local:async request=>{events.push(request.action);if(request.action==='acknowledge')pending=0;
      return {commands:pending?[{command_id:'one'}]:[],counts:{pending,conflicts}};},
    remote:async()=>{events.push('server-confirmed');return {committed:true};},
    refresh:async()=>{events.push('refresh');refreshes++;}});
  await sync.run();assert.equal(refreshes,1);assert.ok(events.indexOf('acknowledge')<events.indexOf('refresh'));
  await sync.run();assert.equal(refreshes,1);
  now+=60000;conflicts=1;await sync.run();assert.equal(refreshes,1);
  conflicts=0;await sync.run();assert.equal(refreshes,2);
});

test('deferred form refresh leaves connectivity unchanged and does not delay the next upload batch',async()=>{
  let canRefresh=false,now=1000,refreshes=0;const sent=[],queue=[];
  const sync=new DashboardSync({now:()=>now,
    local:async request=>{if(request.action==='acknowledge')queue.shift();return {commands:queue.slice(0,1),counts:{pending:queue.length,conflicts:0}};},
    remote:async command=>{sent.push(command.command_id);return {committed:true};},
    refresh:async()=>{if(!canRefresh)return {deferred:true};refreshes++;}});
  await sync.run();assert.equal(sync.state.refresh_deferred,true);assert.equal(sync.state.online,false);assert.equal(sync.nextRefresh,0);
  queue.push({command_id:'new-local-sale'});now+=30000;await sync.run();
  assert.deepEqual(sent,['new-local-sale']);assert.equal(sync.state.refresh_deferred,true);assert.equal(refreshes,0);
  canRefresh=true;now+=30000;await sync.run();assert.equal(refreshes,1);assert.equal(sync.state.refresh_deferred,false);
  assert.equal(sync.nextRefresh,now+60000);
});
test('a failed complete refresh retries the next shared slot and does not claim fresh data',async()=>{
  let failed=true;const sync=new DashboardSync({now:()=>1000,
    local:async()=>({commands:[],counts:{pending:0,conflicts:0}}),remote:async()=>{},
    refresh:async()=>{if(failed)throw Error('snapshot connection lost');}});
  await sync.run();assert.equal(sync.nextRefresh,0);assert.equal(sync.state.refreshedAt,undefined);
  failed=false;await sync.run();assert.equal(sync.state.refreshedAt,new Date(1000).toISOString());
});
