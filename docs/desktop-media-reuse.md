# Native media reuse

`dashboard-media.download(publicRoot, files, request, stopped, privateRoot, reuse)` accepts an optional sixth argument:

```js
{
  publicRoot: previousStorage + '/app/public',
  privateRoot: previousStorage + '/app',
  cacheRoot: profile + '/media-cache'
}
```

The first two reuse roots are optional when only the content cache is available. The private root must be the public root's parent. The cache must be outside both generations' application storage; private and public entries use separate cache directories. A fresh inactive destination is always required. Receipts retain the original shape, with safe aggregate counters separately in `transfer` (`reusedFiles`, `downloadedFiles`, `downloadedBytes`). Tickets are never written to files or receipts.

Every reused file is opened without following its final symlink when the platform supports it. The native process checks every directory ancestor, descriptor identity, actual byte length and SHA256, and checks paths/identities again after materialization. MIME remains bound to the authorized manifest's extension and checksum; the independent PHP generation verification remains mandatory. A valid file is hardlinked into the new generation, preserving one physical copy. Unsupported hardlinks fall back to an independently verified exclusive copy; such volumes can consume another full media copy per retained generation.

New downloads are verified before publication and retained in an area-separated content cache. A candidate discarded because newer account data arrived can reuse those exact verified bytes without another server download. The cache is an optimization, never authority for account scope or checksum: entries are rehashed on reuse.

## Local write audit

Hardlink sharing relies on the existing reviewed append-only media behavior. New local file-writing routes must preserve this contract or use a separate copy before changing shared bytes.

- `DesktopDashboardSettingsImages::destination/store` creates an operation UUID and digest path and opens it exclusively (`xb`); existing paths are rejected. Rollback unlinks only files created by that operation.
- `DesktopDashboardProductImages::copy` creates new authorized media IDs and temporary files; an existing destination is accepted only if its exact bytes already match. It never writes through that inode. The scoped `DesktopDashboardProductFilesystem` preserves protected old media instead of deleting it.
- `DesktopDashboardRestaurantImages::copy` creates files for newly inserted media IDs using exclusive creation; an existing path is only read and verified.
- `DesktopDashboardGoProductImages::bind/store` uses an operation UUID and digest filename and refuses an existing destination before the original upload writes.
- `DesktopDashboardExpenseAttachments::store` uses an operation/actor/branch/digest identity. Existing files are verified and returned; new files are installed by rename, which replaces a directory entry rather than mutating a shared inode. Files remain on the private disk.
- Generic `DesktopDashboardLegacy` and `DesktopDashboardUserProfiles` reject uploads; GO store creation also rejects files. Unsupported local write routes are blocked by `DesktopDashboardJournal`.

Native tests exercise real inode sharing, changed-file isolation, no network calls for unchanged media, retry cache reuse, copy fallback, corrupted bytes, source replacement and destination races. These checks complement the real PHP verification; they do not replace it.

## Server metadata work

The server manifest builds request-local owner membership from the exact original scoped queries, projected once per relevant table. It never grants access from the expanded snapshot's foreign-key parents. Download tickets still re-run the original per-request owner checks.

After scope authorization, a shared thirty-second cache stores only a file identity, canonical MIME, SHA256 and expiry. Its key hashes the resolved path and `dev/ino/mode/size/mtime/ctime`. Cache misses hash and inspect MIME through one open descriptor and verify the descriptor/name identity again before storing metadata. Missing, invalid or unavailable cache entries fall back to an actual hash. Actual download bodies always retain their original fresh checksum check. Files changed in place within the filesystem/PHP timestamp resolution can temporarily get a stale advertised checksum; the body guard rejects those bytes until fresh metadata is available. This is a bounded retry, never permission to accept different bytes.

The synthetic owner regression compares administrator and branch-scoped decisions, permitted user-reference fallbacks, pending-vendor eligibility, missing scopes/columns and an extra user available only through snapshot FK closure. A repeated 3,000-image fixture uses six scoped query projections with no per-image owner query. These checks establish the optimization's query behavior, not a production concurrent load capacity claim.
