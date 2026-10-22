# Live compiler policy upgrade

A live application change previously ran the historical deployment reconstruction
against the changed application. That comparison intentionally describes B3 and
therefore rejected a legitimate later snapshot. Candidate builds now run all four
unchanged reconstruction suites in a separate checkout of
`75c3fe50b3609ef5822904a23d7c4ed0afd07eff`, then independently verify the current
candidate's inventory, Git objects, filesystem bytes, modes and source fingerprint.
The existing Windows package, installer and acceptance checks remain required.

The source compiler installs two pinned CI control files into each candidate.
They remain outside application source authority and do not change the inventory
hash. Fresh and retried candidates verify the control files against their pins.
The worker binds pending candidates to `historical-baseline-v1`; a pending or
blocked candidate from an older policy is retired through the normal preparation
protocol. Accepted current feeds remain current. A waiting replacement after a
missing feed is retained across ticks and process restarts.

`deployment/upgrade_go_erp_live_runtime_root.sh` stages the reviewed payload and
pauses an active timer while waiting for an existing service to finish. Its root
helper validates the original runtime, probes the live source without credentials,
and changes only the service's runtime path. Configuration, base revision, durable
state, counters and publication history remain intact. The original runtime is
retained. Failures restore the original service unit under the watcher lock;
rerunning also reloads an already installed unit to recover an interrupted reload.
The timer retains its initial active or inactive state.

The focused runtime policy workflow exercises candidate tampering, retry
idempotence, policy migration, feed recovery and guarded installation with real
filesystem ownership. It also rechecks the bounded bootstrap memory behavior.
