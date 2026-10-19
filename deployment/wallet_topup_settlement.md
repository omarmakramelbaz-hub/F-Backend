# Verified wallet top-up settlement

Wallet intentions previously relied on the customer completing a browser return.
The deployed server had no processed callback for this path, leaving paid
top-ups pending. `PAYMOB_HMAC_SECRET` is not required by this repair: callbacks
only identify a saved attempt; authenticated Paymob transaction inquiry proves
the actual payment before any balance changes.

Wallet intentions now set dedicated notification and return URLs. Both the
processed callback and the customer return resolve the same settlement service.
Legacy checkout returns also enter that service, preventing duplicate credits
when a customer return, webhook, and recovery overlap.

The service checks a unique saved gateway order, exact currency and amount,
configured integration, live successful standalone transaction, ownership,
pending local state, and non-refunded/non-voided evidence. It locks order,
payment, wallet and user, then commits the amount and all settlement states
together. A completed matching replay returns unchanged. Unknown historical or
inconsistent states require review. Broadcast and partner activation effects
run after commit and cannot reverse a successful credit.

## Deployment

Use `deployment/apply_wallet_topup_settlement_root.sh FULL_COMMIT_SHA` on the
measured production server. The installer adds only the reviewed service,
controller and configuration files, applies narrow verified edits to checkout
and intention creation, and inserts routes while retaining adjacent live
updates. It checks syntax before writing and stores private source backups.
Different checkout source hashes or conflicting new files stop installation.
It clears the route cache and verifies URL, configuration, autoload and routes.
No unrelated migrations, dashboard checkout, config-cache flush, or service
restart is performed.

To reconcile an already paid attempt, append all expected identifiers and amount
to the installer. These values come from the private inspection result:

```sh
--payment-id ID --transaction-id ID --order-id ID --wallet-id ID --user-id ID \
--gateway-order-id ID --amount-cents CENTS --integration-id ID
```

The CLI fetches the exact transaction again, validates every expected field,
and uses the same atomic settlement service. Its output reports credited or
unchanged plus balance before/after; it never initiates a payment or refund.
Application files must be current before recovery, so delayed legacy returns
cannot enter the former unguarded wallet path.

## Tests

```sh
python3 tests/wallet_topup_settlement/native-run.py MARIADB_ROOT PHP_EXECUTABLE ILLUMINATE_AUTOLOAD_PHP
WALLET_TEST_PHP=PHP_EXECUTABLE python3 tests/test_wallet_topup_install.py
```

The settlement suite uses a disposable MariaDB and fake provider responses,
covering concurrency, replay, ownership/proof mismatches, partial-write rollback,
and the real transport's HTTP 201 authentication, shared timeout, response cap
and redirect refusal. Installer tests cover preservation, idempotence and refusal
before writes when reviewed sources differ. Fixtures use synthetic identifiers.
