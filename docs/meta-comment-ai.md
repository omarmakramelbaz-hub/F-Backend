# Public comment AI

The existing Facebook/Instagram comments page gains an independent Owner-controlled reply policy: disabled, continuously enabled, or enabled on selected weekdays and hours in Africa/Cairo. Central Admin accounts can read its status; persisted Owner/Super Admin authorization is required to change it. Each actual policy change establishes a fresh activation boundary. Old comments, comments outside the current schedule window, manually handled comments, and uncertain prior sends are excluded from automatic dispatch.

The feature reuses the existing configured business accounts and AI credentials without changing them. Price discussions receive the approved WhatsApp redirect instead of a public price. Other eligible replies use bounded post/comment context and AI output. The worker currently supplies no catalog names, so unverified item details receive generic clarification rather than invented menu information. The manual comment composer remains available and shares cross-actor reply guards with the worker. Private encrypted read caching and the existing manual-reply improvements are included.

Installation creates only `meta_comment_ai_settings`, `meta_comment_ai_targets`, and `meta_comment_ai_poll_states` through `2026_10_15_010000_create_meta_comment_ai_tables.php`. The new settings row is OFF, with no activation timestamp. Existing settings and all manual/AI send receipts remain intact on reruns or source rollback. Migration performs no historical backfill, provider request, public reply or feature activation.

The strict `deployment/meta_comment_ai_manifest.json` pins every reviewed runtime and unit input. The root launcher verifies its own extracted helper/manifest inputs against the immutable release; the updater verifies all source Git blobs and manifest pins. Existing comments service/controller/view/JS/CSS support the exact e07724c and 99101ec deployments. An exact current baseline selects its reviewed version; otherwise eight-line surgical patches must yield one unique result. Conflicting or ambiguous operator edits are refused. Live `routes/web.php` receives only one AI registration, preserving other Meta/WhatsApp registrations. No Kernel, OAuth credentials, inbox bot, existing scheduler unit, environment file or unrelated route is replaced.

Use the reviewed release SHA from branch `codex/social-comments-ai-20261015`. Extract and verify `deployment/apply_meta_comment_ai_root.sh` from that SHA, then run it as root against the fixed `/home/fasakha/public_html` application and PHP `/opt/cpanel/ea-php82/root/usr/bin/php`:

```bash
bash VERIFIED_LAUNCHER RELEASE_SHA --check --install-timer --repair-permissions
bash VERIFIED_LAUNCHER RELEASE_SHA --install-timer --repair-permissions
```

`--check` is read-only for application files, database, policy and systemd state: it stages PHP/Blade, validates schema and unique guards, checks patch anchors, validates dedicated units and reports readiness without backups, migration, locks, enable/start or provider calls. `--repair-permissions` permits group-write only on reviewed application-owned source leaves in the application's primary group; apply removes that bit atomically. It never changes unrelated files or directory permissions. Unsafe owners, symlinks, world-write/special modes and unknown existing units are refused. Cached routes require operator review; no global route/config/view cache flush occurs. Cached AI credentials can use the existing provider fallback without clearing configuration.

Apply publishes new dependencies first, runs only the targeted AI migration, verifies the new default is OFF, then exposes existing service/UI consumers and routes. PHP is linted before publication, Blade is compiled in memory, and only the changed comments template's compiled cache is invalidated. Existing manual ledger and AI claim uniqueness are checked using read-only MySQL/SQLite metadata, including full-column unique request/pending hashes, target hashes, poll account keys and the settings primary key.

The explicit `--install-timer` option installs only `fasakhansta-meta-comment-ai.service` and `.timer`, using reviewed root-owned files under `/etc/systemd/system`, and enables/starts the timer after source/migration verification. It runs as `fasakha`, uses UMask 0077, and invokes `artisan meta:process-comment-replies --once`. The timer waits 60 seconds after boot and after a completed run; persistent catch-up is disabled. The worker enforces its own overlap lock, policy, schedule and bounded send budget. The service allows 90 seconds for its final bounded transport to settle. The installer never directly invokes `--once`, changes policy, runs another scheduler or makes a provider request. The timer operates independently and still obeys the Owner policy, including an already enabled policy on a rerun.

Polling rotates bounded pages and threads. Reaching older posts or deep comment threads can span multiple timer runs; a one-minute response is not guaranteed.

For a read-only status check after deployment:

```bash
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php deployment/meta_comment_ai_diagnostic.php /home/fasakha/public_html
systemctl status fasakhansta-meta-comment-ai.timer --no-pager
```

The CLI-only diagnostic reports schema/guard readiness, numeric counts, configured-account count, provider configuration, policy enabled/mode and worker heartbeat presence. Failed checks include only the phase, fixed checkpoint, exception class, source basename and line; exception messages, SQL, trace arguments and process output are never reflected. The installer distinguishes malformed output from invalid result contracts and identifies the publication phase in a rollback report. Preflight additionally checks existing source readability as `fasakha`, because staged copies are always readable even when a live file is not. It prints no tokens, customer/comment text, provider response, account IDs or private model credentials and makes no provider call. The command `artisan meta:process-comment-replies --check` is also read-only; it does not run the actual worker.

Root-only source/cache/unit backups and previous timer state are recorded under `/home/fasakha/meta-comment-ai-backups/`. A failed apply restores only leaves that still match its published snapshots, preserves intervening operator edits, and reports `RESTORED` or `NEEDS_REVIEW`. New database tables and migration records always remain; no automatic migration down, table drop, receipt reset or historical resend occurs. Dedicated timer changes made by a failed apply are reverted when the units still match this release.

For a later manual source rollback, first stop this dedicated timer and let an active service finish; if policy was explicitly enabled, disable it through the Owner control before restoring older code. Compare current source/unit blobs with the backup's target IDs, preserve concurrent changes, then atomically restore only the affected leaves with recorded ownership/modes, remove only new files still matching this release, and invalidate only the comments template's compiled cache. Restore/reload the two reviewed units and their recorded previous timer state. Keep all three AI tables and manual send guards. An uncertain public send requires checking the actual post replies, rather than deleting a guard or forcing a retry.

Verification uses isolated fixtures and no live replies:

```bash
python3 tests/test_meta_comment_ai_update.py
bash -n deployment/apply_meta_comment_ai_root.sh
META_COMMENT_AI_LARAVEL_VENDOR=/path/to/laravel8/vendor php tests/meta_comment_ai_diagnostic_test.php
```

The deployment suite covers e077/991 variants, exact pins, preserved concurrent route/source work, read-only preflight, strict/opt-in permissions, targeted migration, read-only unique-index metadata, dedicated timer behavior, source/cache/unit restoration and concurrent-edit preservation. Runtime provider/control/worker/gate and full-page browser tests are separate. After deployment, refresh the comments page, confirm OFF and timer readiness, then use the Owner control to choose continuous or scheduled operation when ready.
