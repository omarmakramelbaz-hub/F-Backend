# Owner balance and private named greetings

This incremental package starts from the live V5 revision
`39e47892c7bee9b440721992a37b13f796225214`. It leaves the V5 installer, renderer,
construction, fixed head, particle settings and approved public audio unchanged.
There is no database schema migration or Windows desktop rebuild.

The Owner's shift-closing card lazily reads the existing authorized aggregate
drawer-balance endpoint. The count-only opening feed does not calculate money.
Branch filters and returned branch keys are checked before displaying an amount;
unavailable balances remain unknown. Admin retains the existing closing count.

Every named greeting derives the current authenticated account and trusted tenant
scope. HTTP/login only enqueue a small private job or read a completed recording;
they never execute a model. Status/audio URLs are session-ticket bound and reject
different users, tenants, renamed identities and expired tickets. Names and audio
stay in `storage/app/go-hologram-voice` with directories0700/files0600. There is no
generic, wrong-name or browser/system-voice fallback.

## Pinned activation

Publish the reviewed immutable commit, download its
`scripts/hologram_named_voice/run_root.sh`, verify the launcher's SHA256, then run
it as root on `srv1836524` with two arguments:

```sh
bash "$go_account_run" "$go_account_revision" --apply
```

`--check` is an optional read-only source/host preflight. `--apply` includes those
checks, so a separate probe step is unnecessary. The manifest contains exactly
12 bounded application changes and 10 small root worker/probe/assets files.
The model weights are downloaded only from the pinned assets manifest,
outside the web root, when ABI/capacity permit generation.

The provider loads the standalone voice routes through Laravel's cache-aware
`loadRoutesFrom` method. The route file declares its own `admin` prefix and
`web`, `lang`, and `IsAdmin` middleware. Legacy includes inside another route
group are ignored, and a per-application marker prevents duplicate registration.
Production `routes/admin.php` is excluded from both the deployment manifest and
its allowed paths; its contents and metadata remain untouched.

The installer preserves unrelated production source, snapshots existing PHP route
caches, applies atomic bounded changes, clears scoped routes/views as `fasakha`,
and prewarms only dashboard account types in central plus every validated active
tenant. Tenant config caches and their signatures remain untouched. A runtime
validation failure rolls application source and saved route caches back. A fresh
PHP process verifies exactly one status/audio route, each with its expected URI
and all three required middleware after clearing routes.

The worker scripts/assets are root:fasakha0640 inside root:fasakha0750 directories.
Worker configuration/receipt are root-owned under `/etc/go-hologram-voice`.
State, runtime and per-scope spools are private0700 under the real `fasakha` UID.
Actual worker/bootstrap checks run as that UID before readiness is reported.

## Capacity and readiness

The approved cold CLI model reached4.38GiB peak in measurement. Generation requires
at least5.5GiB effective available RAM, compatible glibc/libstdc++/OpenSSL, local
FFmpeg MP3 support, disk headroom and a fresh root-owned capacity receipt. Nested
cgroupv2, v1 and no-controller hosts are handled. The worker rechecks memory before
each cold generation. Its service has two model threads, CPUQuota100%, Nice10,
MemoryMax6GiB and OOMScoreAdjust500; it does not share the default Laravel job lane.

Exact prepared Office Admin/Omar recordings can publish while generation is
disabled. Unsafe generation is refused, never substituted. Names without a
resolved pronunciation or with dead jobs remain unavailable. Private per-account
details are retained in each scope's `prewarm-inventory.json`; console output
contains counts, not names or account identifiers.

`GO_HOLOGRAM_ACCOUNTS_READY` requires the installed source, working worker/timer,
enabled safe generation, committed inventories, at least one ready named account,
and zero queued/unavailable accounts across every trusted active scope.

`GO_HOLOGRAM_ACCOUNTS_PARTIAL` means the Owner balance/source changes are installed
but named voice work remains queued, unresolved, disabled by capacity/ABI, or its
worker is not ready. It must not be described as complete voice activation.
The initial run processes at most10 jobs; remaining jobs run in the dedicated
background timer, so a larger account list can legitimately be PARTIAL initially.

## Recheck without reinstalling

Run this root command after background work has progressed:

```sh
/usr/bin/python3 -I -B /usr/local/lib/go-hologram-voice/install_root.py --voice-status
```

It updates the scoped private readiness inventories, can spool newly missing names,
and prints aggregate ready/queued/unavailable counts. It does not download models,
reinstall source or run synthesis. `GO_HOLOGRAM_VOICE_READY` requires all names,
enabled generation and an active timer; otherwise it prints
`GO_HOLOGRAM_VOICE_PENDING`. Exact private pronunciation overrides can resolve
inventory entries without changing tenant config caches.

The worker tick refreshes its explicit trusted runtime list and capacity receipt
before invoking the unprivileged worker. New trusted tenants are picked up without
letting the worker discover arbitrary paths or connect to business databases.
