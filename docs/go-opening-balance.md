# GO opening balance

New GO Customer accounts (`go` / `user`) and GO Partner accounts (`go_partner` / `delegate` or `vendor`) receive EGP 50 exactly once, when their user account is created. A partner application by itself receives nothing; approval creates the pending account and its credit, and subsequent activation preserves that balance. Direct admin-created GO stores receive the same opening credit.

The account and completed charging entry are committed in one database transaction. The entry uses the existing unique `wallets.transfer_reference` column with a deterministic opening-credit reference. Public wallet history identifies its payment source as `opening_balance`; it is not a Paymob receipt or a transfer from another customer's balance. Request-supplied balances cannot override EGP 50 on these creation paths.

Existing account balances are not reset or backfilled. Login, profile changes, password recovery, repeated approval and activation never issue another grant. Fasakhansta accounts retain their existing policy.

No new schema or bulk balance mutation is included. If the existing transfer-reference column has not been installed, the complete charging ledger is still written atomically using the legacy schema and displayed as a regular top-up. Account uniqueness and the transaction still prevent duplicate grants. Installing the existing transfer migration enables the descriptive opening-balance label for subsequently created accounts; registration is never blocked by that optional label.
