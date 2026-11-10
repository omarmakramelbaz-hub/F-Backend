# Living GO hologram and point assembly

This package applies the approved blue/cyan holographic face, restrained orange
energy, projection base, and live depth/particle effects to the opening and
sidebar. The approved rendered artwork supplies the facial detail. The opening
starts at one luminous point and sends many particles outward to assemble that
same portrait. The portrait has natural blinking, slight head movement and a
bounded reaction to pointer movement. During the existing opening greeting,
the lips react to its prepared audio envelope.

The opening preserves its existing named English greeting once after login and
then remains silent while idle. Its nonce, readiness polling, private audio and
worker remain unchanged. Voice-command controls are enabled in production with
`data-hud-commands="1"`. A deliberate click on the talk button opens the panel
and starts one recognition session immediately. The browser may request microphone
permission on first use; a previously denied browser permission cannot be bypassed.
No microphone starts on page load and no text-entry field is added.

The server login page uses the same animated portrait in a wide visual area
beside its existing sign-in form. The bounded login integration changes the
presentation and loads its dedicated CSS/controller; form fields, auth actions
and the existing authentication script are preserved.

## Scope

| Area | Installation behavior |
| --- | --- |
| Dedicated renderer and visual styles | Replace only exact reviewed previous file hashes |
| Premium artwork and command files | Create when absent or upgrade only exact reviewed prior hashes |
| Opening JavaScript, home/sidebar Blade templates and login Blade | Apply unique bounded fragments for a reviewed baseline; reject ambiguous variants |
| Existing counters, account grants, greeting and balances | Preserve current implementation outside those fragments |
| Database, routes, controllers, voice worker and Windows installer | No changes |

The package is based on
`47118f609f16cc704fed041a7c8c2c559c9a5e43`. The builder also verifies deployment
against `47c562af4a7a97509f1ae1947ebee11cc4da2b9a`, whose opening JavaScript has
different polling and greeting behavior, and the installed premium release
`7dc7c60a8ff69ab03212c4aa44a47bc4724df148`, plus the installed living-portrait
release `4f6e6985113da853368511e221f6e76473a9cd07`. The bounded fragments preserve
unrelated newer code on every supported baseline. A previously new file may
be created on an older installation or upgraded from an exact reviewed hash;
an unknown collision is refused. Conflicting bounded variants and changes
overlapping a reviewed fragment are refused before any application file is written.

## Publish and activate

After the final reviewed source exists, build the manifest and pin the runner:

```sh
python3 -B scripts/hologram_premium/build_manifest.py --pin-runner
python3 -B scripts/hologram_premium/test_package.py
bash -n scripts/hologram_premium/run_root.sh
```

Normal builds read the four immutable baselines from Git. An offline checkout
may set `GO_PREMIUM_BASELINES_DIR` to a directory containing
`source-index.json` and `<revision>/<repository-path>` snapshots. The index maps
each reviewed revision and allowlisted path to its exact Git blob SHA, or `null`
when that path did not exist. Every retrieved snapshot is checked against that
Git blob identity; missing or corrupted entries fail the build. This path is for
building/testing the release and is not used by the production installer.

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
backup directory. These results describe source/runtime checks. After activation,
check the point-to-particle assembly, blinking and slight head/pointer response;
perform a new login to check the existing named greeting and lip movement, then
verify that the avatar stays silent at idle. Production activation remains an
operator step when no server session is connected.
