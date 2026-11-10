# Premium GO hologram and scoped commands

This package applies the approved blue/cyan holographic face, restrained orange
energy, projection base, and live depth/particle effects to the opening and
sidebar. The approved rendered artwork supplies the facial detail; the canvas
supplies the animated three-dimensional particle layers and response effects.
The face does not rotate. It is not a general conversational AI model.

The command panel supports selected Arabic commands from a microphone button.
Interaction is voice-only, with no text input. Destinations come from the current account's authorized cards;
metric answers read the values currently displayed. Command responses use an
available Arabic browser voice. The existing named English login greeting and
its private voice worker remain separate and unchanged. Microphone support and
Arabic voices depend on the browser/device; status messages explain missing support.

## Scope

| Area | Installation behavior |
| --- | --- |
| Dedicated renderer and visual styles | Replace only exact reviewed previous file hashes |
| Premium artwork and command files | Create once; reject a conflicting existing file |
| Opening JavaScript and four Blade templates | Apply only unique bounded integration fragments |
| Existing counters, account grants, greeting and balances | Preserve current implementation outside those fragments |
| Database, routes, controllers, voice worker and Windows installer | No changes |

The package is based on
`47118f609f16cc704fed041a7c8c2c559c9a5e43`. The builder also verifies deployment
against `47c562af4a7a97509f1ae1947ebee11cc4da2b9a`, whose opening JavaScript has
different polling and greeting behavior. The bounded fragments preserve that
newer code. Unknown changes overlapping a fragment or replacing a dedicated
renderer/style are refused before any application file is written.

## Publish and activate

After the final reviewed source exists, build the manifest and pin the runner:

```sh
python3 -B scripts/hologram_premium/build_manifest.py --pin-runner
python3 -B scripts/hologram_premium/test_package.py
bash -n scripts/hologram_premium/run_root.sh
```

Commit the resulting immutable files together. Download `run_root.sh` using that
commit SHA, verify the runner's separately published SHA-256, and invoke it as
root on `srv1836524` with the same immutable commit:

```sh
bash "$go_premium_run" "$go_premium_revision" --apply
```

`--check` performs source/tenant preflight without changing application source.
`--apply` includes that preflight, so a separate check is optional. The launcher
pins the installer and manifest hashes and downloads only those two files from
the supplied 40-character commit. It never fetches a moving branch or resets the
production checkout.

The application directory is fixed at `/home/fasakha/public_html`. Before any
write, all source entries are planned and ambiguous drift is rejected. Every
changed existing file is backed up privately under
`/root/go-hologram-premium-backups`, with original ownership, mode and hashes in
the receipt. Publication uses atomic writes and rechecks source bytes to detect
concurrent edits. Existing modes and ownership are preserved.

The installer compiles and lints changed Blade source in the central application
and every validated active Owner runtime, running PHP as `fasakha`. It clears
only each runtime's compiled views; route and configuration caches are preserved.
It verifies installed bytes after application. Failure restores the original
source and clears isolated views again. If an operator edited a file after
publication, rollback preserves that edit and reports a conflict instead of
overwriting it.

An unchanged replay reports `GO_HOLOGRAM_PREMIUM_ALREADY_APPLIED`. A successful
first application reports `GO_HOLOGRAM_PREMIUM_APPLIED_AND_VERIFIED` with the
backup directory. These results describe source/runtime checks, not a live
microphone permission grant or successful browser speech recognition. Production
activation remains an operator step when no server session is connected.
