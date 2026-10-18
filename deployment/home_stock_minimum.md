# Homepage stock minimum highlighting

The update shows a red balance and a minimum-reached label on the main dashboard
when an ingredient reaches or falls below its configured per-branch minimum.
An all-branch balance remains a total; it warns when any selected branch is low.
A blank minimum stays disabled and zero warns at depletion. Existing negative
balance warnings remain visible.

Prerequisite: the branch minimum feature deployed at
`d7b0f1cb122251adc34fb62f9a0be7a203660ffc`, including its database migration.
This update changes six source files. It does not run migrations, dispatch mail,
modify minimum settings, or change the existing scheduler.

After fetching the pinned release, run the checked root wrapper with its full
revision: `bash apply_home_stock_minimum_root.sh FULL_REVISION --check`, then the
same command without `--check`. Extract the wrapper from that exact Git object
and verify the reviewed blob hash before execution, as in the release command.
The wrapper runs the Python installer as `fasakha` with cPanel PHP 8.2.

The installer stages per-file Git hunks against the pinned baseline, preserves
unrelated live edits, checks PHP syntax and JavaScript syntax if Node is
available, and verifies destination ownership and writability. Unrecognized
conflicts stop before publishing. It never checks out or resets the live tree.

Backups and receipts are private under
`/home/fasakha/home-stock-minimum-backups/`, outside the public app directory.
Failure reverses only exact installed bytes and restores original permission
bits. A concurrent independent change is retained and explicitly listed for
review. Repeating an already successful release leaves sources untouched.

Validation: `python3 -m unittest discover -s tests -p test_home_stock_minimum_install.py`.
