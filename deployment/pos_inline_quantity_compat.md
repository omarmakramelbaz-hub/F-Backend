# Invoice quantity deployment compatibility

The first `91582ac` installer stopped during its read-only prerequisite check because the live order board already contained the alert and lifecycle releases. No source files were published by that attempt.

This follow-up applies the same invoice quantity, application print policy and internal logo changes over the reviewed live overlays:

- Order board service: `6df7fec` plus the `cab9f03` operating-day date filter. The reconstructed baseline blob is `4eeac847ef0e854fcfe417468f8a951af1afc3dc`, matching the supplied server diff.
- Order board controller and invoice view: `6df7fec`, retaining scoped alert keys, preparing cancellation and saved preparation labels.
- Phone composer: `9f4cce8792467d1899927066f2c951cb2fa10cb5`, retaining the persistent delivery queue and composer draft.

The installer continues to publish only its twelve declared source targets. It does not install the lifecycle, app-editor or unified-phone prerequisites. Source-equivalent prerequisite files are restored in this branch to keep the checkout usable; their live counterparts are preserved by deployment.

Patches are built from pinned Git objects, staged and checked before publication. Known prerequisite overlays are normalized only in temporary proof storage. Unknown overlapping edits remain blocked. Unrelated compatible edits survive, and rerunning an installed release is a no-op. The existing private backups, publication ordering and coherent rollback remain in use.

No migration or broad cache clear is performed. The real Laravel route, schema, transaction-engine and Blade preflight runs on the server before publication; isolated tests stub that database-dependent probe.
