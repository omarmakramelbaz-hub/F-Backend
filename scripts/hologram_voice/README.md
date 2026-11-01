# Private named greeting worker

`worker.py` consumes only the roots explicitly listed in a trusted configuration.
It has no HTTP listener, database access, tenant discovery, browser TTS or login
hook. Install the helper files and small assets outside the web root. Run as the
same UID as Laravel's private spool, with a root-owned configuration and receipt.

```json
{"version":1,"runtime_root":"/private/voice/runtime","assets_root":"/private/voice/assets","state_root":"/private/voice/state","capacity_receipt":"/etc/go-hologram-voice/capacity.json","generation_enabled":false,"ffmpeg_path":"/usr/bin/ffmpeg","threads":2,"min_available_memory_bytes":5905580032,"runtimes":[{"scope":"central","spool_root":"/trusted/runtime/storage/app/go-hologram-voice"}]}
```

The installer creates `state_root` and each configured spool as mode 0700, owned
by the worker UID. The worker creates queue/processing/ready/dead below each
spool, also 0700; descriptors and outputs are 0600. Tenant scope is
`tenant:<trusted runtime ID>`, never supplied by the browser. The PHP producer
owns the exact version 1 identity/key contract; the worker revalidates it before
using a name or selecting an exact-name prepared clip.

```bash
python3 bootstrap_runtime.py --install-assets --assets-root /private/voice/assets
python3 bootstrap_runtime.py --install --runtime-root /private/voice/runtime
python3 worker.py --config /etc/go-hologram-voice/config.json --once
```

Bootstrap downloads only the fixed SHA256/size-pinned official llama.cpp b12032
archive and Qwen3-TTS1.7B GGUF weights in `assets.json`. `--check` verifies assets
or runtime without downloading or synthesizing. Library aliases are materialized
as ordinary files and checked against the pinned archive, not an editable
installed-file record. The approximately 1.5 GB weights are never in Git.

New-name generation additionally requires an unexpired private receipt:

```json
{"safe":true,"runtime_root":"/private/voice/runtime","model_manifest_sha256":"8675b9323563709b3b9b650838e834d9f1f3b5ff46eeb770b1cb1f47a53a3969","checked_at":1793574000}
```

The receipt expires after 24 hours. Host and cgroup memory headroom are rechecked
before each cold process; the hard available-memory floor is 5 GiB. The exact model
used 4.38 GiB after synthesis in a compact-context CPU test. Disabled generation,
stale receipts or insufficient memory leave unknown jobs queued without spending
an attempt. Prepared exact spoken names `Office Admin` and `Omar` need no model.

One global lock serializes all configured scopes. Each invocation processes at
most1job by default (`--max-jobs` 1..10), scans at most 1000 descriptors and rotates
the starting scope. CLI synthesis uses low priority, a 240-second wall timeout,
180 CPU-second bound, 16 MiB output limit and no shell interpolation. The child exits
after the prefix, releasing model RAM. Three failed attempts lead to a private
reason-code dead marker; retries back off. Processing leases expire after 15 minutes,
and old private staging directories are removed after the same interval.

The approved common-tail PCM is copied unchanged after the new prefix. Only the
prefix receives the approved tempo/loudness processing. MP3, 50 Hz amplitude envelope
and identity/hash metadata become visible together through an atomic directory
rename. A silent prefix or one hitting the 350-frame generation cap is refused
rather than publishing a clipped name. Display identities allow 150 characters;
reviewed Latin spoken-name spellings allow 450. Arabic conversion and exact-account
pronunciation overrides belong to the producer; unknown scripts are refused.
