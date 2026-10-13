# WhatsApp grounded extraction and native content recovery

The reported conversation reached a completed HTTP 200 provider response, but its extraction failed the existing evidence validator. The previous sanitizer hid `INVALID_EVIDENCE` as `UNKNOWN`. The discarded candidate does not establish which field failed.

The provider now explicitly requests citations for separate customer name, phone, address and area messages. The validator retains its original acceptance rules and public `validate()` result shape. An additive detailed result supplies fixed predicate and field categories without rejected values or provider prose. Substantive failures appear as `INVALID_EXTRACTION`, rather than triggering network retries.

Thirteen captured native messages were authenticated `unsupported` placeholders with error 131060, followed by text under the same native ID. Recovery accepts only that exact error-only transition with matching scope, peer, identity, routing and context, and an authentic timestamp within one hour. It preserves canonical IDs, immutable raw events and encrypted before-images. A shared pure proof revalidates the raw payload, original ciphertext and corrected projection before order analysis.

Only a proven semantic correction wakes the affected idle scan. Active claims cause complete transaction rollback. Completed or sealed order evidence cannot be rewritten. Recovered historical confirmations remain `RECOVERED_HISTORY_REVIEW` until a new full business summary and genuine customer commitment after recovery. Activation cutoffs, completed receipts, command UUIDs, price checks, branch permissions and Cairo store-to-Shubra fulfillment rules stay unchanged.

## Installation and observation

`deployment/apply_whatsapp_order_semantic_repair_root.sh --release <reviewed-commit>` publishes the nine pinned sources and recovers only events 5945, 6135, 6182, 6436, 6514, 6515, 6827, 6837, 6902, 6989, 7141, 7182 and 7338. It holds both installation locks and pauses the existing capture/order units across publication and one recovery transaction. All configuration bytes and metadata are preserved. Backups contain private encrypted before-images and the prior pipeline state. Successful completion restores the previous timers; uncertain post-publication recovery keeps order processing paused for review.

The recovery creates no orders and makes no AI calls. It does not retry or clear unrelated quarantines. Event 7215 remains an unresolved status notification and continues to block automatic dispatch.

`deployment/whatsapp_order_ai_read_only_probe.php --probe-one` makes at most one extraction for conversation 11, bounded by the existing activation, scan and dispatched floors. It checks source, configuration and semantic state before and after the request, and prints only validated categories, counts and presence flags. It performs no projection, analysis persistence, order submission or WhatsApp sending. Its status-only metadata separately identifies an allowlisted status for event 7215 without printing arbitrary event values.

## Validation

The regression coverage includes unchanged extraction outcomes, split customer-data citations, safe failure observations, real SQL hydration and replay, active-claim rollback, protected order evidence, later batch-member rollback, encrypted backup/CAS recovery, pipeline restoration and the explicitly bounded read-only probe. CI runs these checks on PHP 8.2, including the production Laravel 8.83.29 framework.
