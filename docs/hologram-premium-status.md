# GO hologram: verified release and continuation

Last verified on 10 November 2026 (Egypt).

## Accepted outcome

- Keep the approved blue/cyan portrait with restrained orange energy and a projection base.
- Assemble that portrait from one luminous point and many particles, as in the earlier opening.
- Add natural blinking, slight head movement and a bounded pointer response.
- Move the lips during the existing named opening greeting, then remain silent while idle.
- Use the same approved portrait in the opening and the compact sidebar.
- Keep the existing named English greeting once after login; preserve its worker, nonce and readiness behavior.
- Hide the optional microphone/command panel by default; do not add text entry.
- Preserve the existing login greeting, counters, drawer scopes and other dashboard behavior.

## Previously verified immutable release

- Branch: `codex/hologram-premium-commands-20261110`.
- Reviewed application commit: `7dc7c60a8ff69ab03212c4aa44a47bc4724df148`.
- Passing workflow: https://github.com/omarmakramelbaz-hub/F-Backend/actions/runs/42750149110
- Runner: `scripts/hologram_premium/run_root.sh`.
- Runner SHA-256: `4cb3596b99c3725e674c910edff81215991b6bc4c54d8d19fd065693c3ab2746`.
- Manifest SHA-256: `6c534170432185048fda544c4fcbefbfeb12720a0aaded0eda3a3c1f73af2442`.

## Completed validation

The workflow above completed successfully on that exact application commit:

- 24 renderer lifecycle checks.
- All 13 bounded-deployment tests, including application against both reviewed baselines.
- 69 voice-command browser assertions with controlled browser speech services.
- 178 existing opening-behavior browser assertions.
- 100 premium browser assertions across seven viewport sizes with real WebGL, the approved portrait, voice-only controls, and cleanup.

Exact release payload bytes and the installer/manifest pins were independently checked after retrieving the source.

Browser speech recognition and Arabic voice availability still depend on the user's browser/device. Automated service stubs verify the controls and command lifecycle; they do not establish microphone accuracy or audible output on the production device.

## Activation status and next step

The receipt below confirms the earlier premium portrait release. The newly
requested point assembly, living face and silent idle changes are a subsequent
upgrade: they require final motion validation and a newly pinned activation
command before being described as installed.

The user supplied a successful production receipt from `srv1836524` on 10 November 2026 at 05:43 Egypt time (03:43 UTC):

```json
{"status":"GO_HOLOGRAM_PREMIUM_APPLIED_AND_VERIFIED","feature":"hologram-premium-commands-20261110","changed_files":11,"tenant_contexts":0,"backup":"/root/go-hologram-premium-backups/20261110T034300Z-7d2718d6","database_changes":false,"route_changes":false,"voice_worker_changed":false,"desktop_rebuilt":false}
```

Activation of the central application is confirmed by that operator-supplied receipt; no server session was used by the assistant. `tenant_contexts: 0` counts additional Owner runtimes, while the central runtime is always verified separately. The receipt does not establish verification of separate Owner runtimes. No extra server action is indicated by this successful result.

The command below records the exact activation used. It is retained for reproducibility; reinstalling is not required:

```bash
bash <<'SH'
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524

go_hologram_rev=7dc7c60a8ff69ab03212c4aa44a47bc4724df148
go_hologram_run=$(mktemp /var/tmp/go-hologram-premium-resume.XXXXXXXX)
trap 'rm -f -- "$go_hologram_run"' EXIT

curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_hologram_rev/scripts/hologram_premium/run_root.sh" \
  -o "$go_hologram_run"

printf '%s  %s\n' \
  4cb3596b99c3725e674c910edff81215991b6bc4c54d8d19fd065693c3ab2746 \
  "$go_hologram_run" | sha256sum --check --status

bash "$go_hologram_run" "$go_hologram_rev" --apply
SH
```

Expected first-application marker: `GO_HOLOGRAM_PREMIUM_APPLIED_AND_VERIFIED`.
A successful unchanged replay reports `GO_HOLOGRAM_PREMIUM_ALREADY_APPLIED`.

The remaining check is on the production device: refresh the dashboard with `Ctrl+Shift+R`, verify the approved portrait in the opening and sidebar, open the command controls, press the microphone, and allow microphone access. Try `عدد العملاء كام؟` to verify an audible Arabic reply, then `افتح العملاء` to verify navigation. An installed Arabic browser voice is required. Use one command per microphone press.

Use this file and the immutable workflow/commit above to resume after a conversation interruption. The earlier premium portrait release is verified and activated in the central application. Complete validation and activation of the requested living-face/point-assembly revision next, then verify the named opening greeting and silent idle behavior on the production device.
