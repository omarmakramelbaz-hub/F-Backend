# Order editor feedback patch

This incremental release changes only:

| Application path | Surgical baseline |
|---|---|
| `public/dashboard/js/order-board-items.js` | `74a0070907d9ffbaa0f3b5aa7b6082bc1c345878` |
| `public/dashboard/js/order-board.js` | `5287afa43a603dd4a200498bc28cd283c683001b` |

The patch bounds editor loading to 15 seconds, including response headers and JSON, and shows a retry option when loading fails. Save stays disabled while content is unavailable. A successful rejection hides its confirmation notice; other action confirmations retain their existing behavior. These reviewed code changes await deployment. The installer preserves unrelated live changes through uniquely anchored eight-line patches, refuses overlapping or ambiguous operator edits, and safely accepts an already installed release.

Use the full reviewed release SHA supplied for this patch from branch `codex/order-alert-menu-fix-20261014` (draft PR 83). Extract the launcher `deployment/apply_order_editor_feedback_root.sh` from that immutable release and verify its Git blob before running it as root with `RELEASE_SHA --check`. The launcher verifies the Python installer blob; the installer also verifies each baseline and release source blob. Deployment is restricted to `/home/fasakha/public_html` and reads Git sources as `fasakha`.

`--check` reads and validates permissions and patch anchors without publishing files or creating backups/locks. It requires the existing source leaves to have safe owners and non-writable group/world permissions. It does not alter permissions or invoke PHP, a database, Artisan, caches or Node. Run JavaScript syntax and behavior regressions before pinning the release.

Apply using the same verified launcher with `RELEASE_SHA`. It preserves both leaves' ownership/modes, stores a root-only backup and manifest with target blob IDs under `/home/fasakha/order-editor-feedback-backups/`, atomically publishes the two JavaScript files, and checks for concurrent edits. On failure it restores published leaves when they still match this deployment. `ORDER_EDITOR_FEEDBACK_ROLLBACK_NEEDS_REVIEW` means an intervening operator edit was preserved for review. Only these two files participate; the previously installed sound/menu repair remains in place.

Validation commands:

```bash
python3 -m unittest discover -s tests/deployment -p 'test_order_editor_feedback_installer.py'
bash -n deployment/apply_order_editor_feedback_root.sh
node --check public/dashboard/js/order-board-items.js
node --check public/dashboard/js/order-board.js
```

After deployment, refresh the board and open an order editor. Confirm a successful load displays the existing menu, a failed or stalled load offers retry, and Save stays disabled until the menu is available. Close and reopen during loading to verify stale responses cannot replace the current editor. Reject an order and confirm the card refreshes without a green confirmation; confirm other order actions still show their usual feedback.
