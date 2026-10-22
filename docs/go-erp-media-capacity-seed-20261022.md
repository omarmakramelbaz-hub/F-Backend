# Keep subsequent automatic releases compatible with the media capacity fix

The installed source watcher builds native client code from its trusted Git
seed. Updating only production PHP would allow a later automatic release to
inherit the older one-GiB native limit. The capacity rollout therefore upgrades
that seed along with the single production PHP source file.

The fixed capacity core is `d27b2942848714c455f55c9bf2c3dc52f7b89c25`.
The Windows installer and actual native acceptance belong to that core revision.
The separate seed branch contains policy-only changes, so application and native
bytes remain identical; its full update contracts are run independently.
The policy seed must descend from it and may differ only in the listed seed
verification, deployment, tests and workflow policy files. All application,
native, dependency and build bytes must remain identical to that core.
Future captured candidates still pass the original independent full inventory,
raw Git bytes, version, source and parent verification against this exact seed.
The original immutable baseline remains accepted through its original proof.

`apply_go_erp_media_capacity_root.py --release <policy-seed> --apply` requires
root on srv1836524, the exact previous PHP blob and original private watcher
seed, clean private checkout, compatible durable state and the complete matching
live source cohort. It acquires the existing watcher lock without stopping the
timer. Busy workers cause refusal. The new public Git seed is staged, verified
and syntax checked before changes. The old checkout, PHP file, private config
and state are retained in a root-only backup. Only the source file, watcher
base, and capture-specific state are changed; credential, counters, version and
sequence floors, publication history and dependency guard are preserved.
Failure restores known before/after bytes without overwriting an unexpected
concurrent source edit. No production PHP bootstrap, migration, media rewrite
or financial operation is executed by this deployment helper.

Run the matching tested installer under the existing Windows account and
directory, then retry preparation online. The installed source and seed receipt
alone is not offline readiness; preparation and a disconnected read test must
still succeed. The next watcher tick can build a higher compatible release
using the existing complete CI and publication gates.
