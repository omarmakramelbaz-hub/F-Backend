# Social post comments

The central Owner and Admin dashboard gains **تعليقات السوشيال ميديا** below the Meta inbox. Facebook Pages and Instagram business accounts use the existing configured Meta account allowlist and tokens. Branch accounts remain denied by the persisted `MetaInboxAccess` identity check. Offline desktop mode cannot use this online feature.

Users select a business account, a post, and a comment to read its replies and publish a public reply under that business account. The selected post is refreshed by polling. This release does not register a feed/comments webhook, replace OAuth settings, change Messenger or WhatsApp, or enable automatic AI replies.

The composer includes 56 Unicode emojis with Arabic and English names. Emojis replace the current text selection or insert at the caret, and an emoji can be published on its own. Replies appear immediately with a pending label. Background reads pause during the foreground write, and a confirmed receipt replaces the pending row without requiring another comments fetch. An uncertain outcome remains visibly uncertain and blocks resending; a definitive rejection keeps the draft available.

The sidebar placement is scoped to the native sidebar and survives delayed menu regrouping and SPA navigation. Comments appears directly below the Meta inbox, with one active link on the comments route.

Reply responses include numeric `Server-Timing` values for the controller write path and Meta transport phases. The write still uses one provider POST and retains the persisted actor/account checks. These timings exclude middleware and PHP-FPM queue time; Meta's actual response time remains external to the dashboard.

## Access prerequisites

Facebook Page tokens need `pages_read_engagement`, `pages_read_user_content`, and `pages_manage_engagement`. Instagram with Facebook Login needs `instagram_basic`, `instagram_manage_comments`, and `pages_read_engagement`; Instagram Login needs `instagram_business_basic` and `instagram_business_manage_comments`. Existing messaging permissions alone do not imply comment access. A permission or token failure appears inside the tab with a reconnect action. Grant the requested permissions to the same business account through its Meta connection and review the capability output before testing a public reply.

The read-only diagnostic reports per-account post readability and missing permission names. It never posts a comment, subscribes a webhook, prints tokens, prints customer text, or changes account credentials. Tokens remain on the server.

## Targeted installer

`deployment/apply_meta_comments_root.sh RELEASE_SHA` runs on the existing `/home/fasakha/public_html` server with the fixed PHP 8.2 executable. The wrapper retrieves the updater and source manifest from the exact reviewed Git release and verifies their Git object hashes. The manifest has the shape `{"version":1,"files":{"relative/path":"git-blob-sha1"}}` and must contain exactly `SOURCE_FILES` from the updater. Every published payload must match its manifest hash.

The updater checks the existing central authorization foundations without writing them, permits read-only hardlinked foundations, refuses symlinks or hardlinked write targets, rejects unknown installed comment files, and accepts the reviewed sidebar baseline or the exact new sidebar. Live `routes/web.php` receives one comments registration while preserving existing Meta, orders, webhook, and unrelated route changes. The native navigation script receives only the comments priority route and its own narrowly marked DOM ordering block; existing inbox shortcut logic and custom routes remain intact.

All PHP files are linted in staging and the two affected Blade views are compiled and parsed before publication. Runtime checks require an allowed persisted central Owner, a working central inbox, at least one configured Meta token, working encryption, and uncached routes. The comments migration alone runs using its exact path. After dependencies and the table exist, routes and sidebar activation are published and all six routes are checked for their controller actions, HTTP methods, and `web`, `lang`, and `IsAdmin` middleware. No cache flush or unrelated migration runs.

The source installer makes no Graph requests. After successful installation the wrapper runs the separate read-only capability diagnostic; a missing permission does not roll back the usable connection/reconnect tab.

## Backups and rollback

Before the first application write the installer saves only affected source files and a source manifest in a private timestamped `meta-release-backups` directory. It preserves source ownership and modes, writes by atomic replacement, and checks for concurrent changes before and after publication.

An installation failure restores the source files it published and removes new files only while their bytes and identity still match the published state. Concurrent operator changes are preserved and reported as `NEEDS_REVIEW`. `meta_comment_reply_requests` and its migration record remain after rollback: no table, row, or operational data is dropped. This table stores reply deduplication and send status; its creation is non-destructive.

The installer never resets or checks out the full repository and never overwrites the server's current order-editor, order-sound, desktop POS, message bot, OAuth, webhook, or unrelated dashboard changes.

## Verification

`python3 tests/test_meta_comments_update.py` uses an isolated application fixture and mocked PHP, migration, and Git processes. It checks manifest pins, narrow route/navigation deltas, idempotency, exact migration scope, staging permissions under umask 077, unknown-file refusal, symlink/hardlink safety, concurrent edits, and source restoration after runtime or migration failure. Runtime service and UI tests are separate and do not send live public comments.
