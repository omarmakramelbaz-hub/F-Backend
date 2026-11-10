# GO hologram: verified release and continuation

Last verified on 10 November 2026 (Egypt).

## Accepted outcome

- Keep the approved blue/cyan portrait with restrained orange energy and a projection base.
- Animate the particle/depth effects; do not rotate the head.
- Use the same approved portrait in the opening and the compact sidebar.
- Voice interaction only: a microphone button and Arabic spoken replies, without a text-entry field.
- Support the documented Arabic commands using the current account's authorized cards and displayed values.
- Preserve the existing login greeting, counters, drawer scopes and other dashboard behavior.

## Immutable release

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
- The existing opening-behavior browser suite.
- 100 premium browser assertions across seven viewport sizes with real WebGL, the approved portrait, voice-only controls, and cleanup.

Exact release payload bytes and the installer/manifest pins were independently checked after retrieving the source.

Browser speech recognition and Arabic voice availability still depend on the user's browser/device. Automated service stubs verify the controls and command lifecycle; they do not establish microphone accuracy or audible output on the production device.

## Activation status and next step

Production activation has **not** been performed from this session. Do not describe the update as installed until the server returns its success marker.

Run the following as root on `srv1836524`:

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

After activation, verify the approved portrait in the opening and sidebar, allow the microphone when prompted, and try `افتح العملاء`, `عدد العملاء كام؟`, and `رصيد أدراج الفروع كام؟`. Confirm the Arabic reply is audible on the production device.

Use this file and the immutable workflow/commit above to resume after a conversation interruption. The application implementation is complete and verified; the remaining task is server activation followed by a real-device voice check.
