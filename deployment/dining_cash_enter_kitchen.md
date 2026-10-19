# Dining cash Enter sends to kitchen

Based on `bc3de8f0314abe1aebe7fc4b14e09f31fa57a98a` (the deployed dining Enter / delivery single follow-up release).

Before a bill is printed, a distinct Enter press in the dining amount-paid field now calls the existing `saveAndSend()` path after checking that a current quote and sufficient valid cash are present. It saves the order, sends it to preparation, and uses the existing kitchen print path. Customer, waiter, guest count, permissions, write locks, immutable retries, and idempotency checks still use the existing flow. Holding Enter, modifiers, and composition cannot send. A previously sent, unchanged order cannot be sent again through cash Enter. A changed order can use the same existing save-and-send path. After bill printing, cash Enter still only focuses the final collection button.

Only `public/dashboard/js/dining-pos.js` is patched on the server. The standalone installer derives exact-context hunks from the pinned baseline and release; it stages them against the live file, preserves independent changes, rejects overlapping edits, checks snapshots before publication, and replaces the merged file atomically. It saves the original and a hashed receipt under the private `/home/fasakha/dining-cash-enter-kitchen-backups/` directory. Repeats return `DINING_CASH_ENTER_KITCHEN_OK already_current=1 files=0`. Rollback restores owned bytes and preserves concurrent operator edits for review.

The root wrapper requires `srv1836524`, verifies the Python installer blob, and runs it as `fasakha`. It loads hash-verified filesystem/Git helpers from the pinned utility commit without invoking an older feature installer. No PHP, routes, database, migrations, consent records, or cache changes are needed. The dining JavaScript URL already includes `filemtime`.

Checks:

```sh
node --check public/dashboard/js/dining-pos.js
python3 -m unittest discover -s tests -p 'test_dining_cash_enter_kitchen_update.py' -v
bash -n deployment/apply_dining_cash_enter_kitchen_root.sh
CRM_TEST_PHP=/path/to/php CRM_TEST_AUTOLOAD=/path/to/vendor/autoload.php node tests/browser/dining-compact.cjs
```

Apply the hash-verified root wrapper with the full release SHA as its only argument. Direct Python `--check` mode stages the patch without publishing application bytes.
