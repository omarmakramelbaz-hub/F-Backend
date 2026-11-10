# GO hologram: voice interaction, living portrait and server login

Last verified on 10 November 2026 (Egypt).

## Voice restoration follow-up

The talk control is available beside the living portrait.
A single deliberate click opens the panel and starts Arabic recognition, with
the browser's normal permission prompt on first use. No settings page is opened
and no microphone starts on page load. A permission previously denied in the
browser still requires the user to allow this site; the application cannot
bypass that decision. Typed commands are not introduced.

The follow-up changes only the production command script and the opening's
enable flag when applied over the activated living release. The portrait,
motion, sign-in form, existing greeting and account-scoped cards remain intact.
Both exact-commit browser workflows passed for application revision
`7258fd82706494020aa9c4262d41a1ce354ed442`:

- PR workflow: https://github.com/omarmakramelbaz-hub/F-Backend/actions/runs/42757824517
- Push workflow: https://github.com/omarmakramelbaz-hub/F-Backend/actions/runs/42757818730
- 19 deployment tests across four reviewed baselines and 48 renderer checks.
- 69 command assertions and 191 existing-opening assertions.
- 135 production-DOM portrait/voice checks across seven viewport sizes,
  including a single talk-button gesture, Arabic metric speech with avatar
  speaking state, and a real navigation to the authorized card destination.
- 29 assembly/facial-motion checks and 84 unchanged-authentication login checks.

The speech services are controlled browser stubs. They establish interaction
and cleanup behavior; actual microphone recognition and installed Arabic voice
availability still require the production device.

Immutable voice-restoration package:

- Application commit: `7258fd82706494020aa9c4262d41a1ce354ed442`.
- Runner: `scripts/hologram_premium/run_root.sh`.
- Runner SHA-256: `6eb25f2b59d6e5e11d1c86a35e7bc963a3755401015cb893de6c6df7ad04cc97`.
- Installer SHA-256: `56de0984d1bf4cbd1aacbf091431783d4055442d2830f7880133cf1854f04d75`.
- Manifest SHA-256: `27ddb82d200f87c112054910e01b9cf5e78c454dc19c8b929ee4262124fec6b9`.

Production activation of this voice follow-up is pending. The earlier receipt
below confirms the living portrait/login release, not this follow-up. No remote
server command was executed by the assistant.

## Current outcome

The approved blue/cyan portrait assembles from one light point and many coloured
particles. It blinks, moves its head slightly, responds within bounded angles to
pointer movement and moves its lips during the existing named opening greeting.
The opening and compact sidebar share the same portrait. The voice follow-up
restores its talk button; there is no typed-command field.

The server sign-in page matches the desktop layout: large hologram left, existing
sign-in form right, stacked on narrow screens. The form, POST action, CSRF,
remember option, password controls and authentication handler are retained.
The login avatar is silent. After successful sign-in the existing named English
greeting runs once; refresh, ordinary navigation and idle remain silent.

## Verified living-portrait release (already activated)

- Branch: `codex/hologram-premium-commands-20261110`.
- Application commit: `4f6e6985113da853368511e221f6e76473a9cd07`.
- Passing PR workflow: https://github.com/omarmakramelbaz-hub/F-Backend/actions/runs/42754040461
- Passing push workflow: https://github.com/omarmakramelbaz-hub/F-Backend/actions/runs/42754033058
- Runner: `scripts/hologram_premium/run_root.sh`.
- Runner SHA-256: `1be711b3c0ba4fffa566af8c62f99040fbc1c0c056520eff2fef72d16017108c`.
- Installer SHA-256: `56de0984d1bf4cbd1aacbf091431783d4055442d2830f7880133cf1854f04d75`.
- Manifest SHA-256: `fadc951557291f7057b3b921034b6dee846b734a0141bf958cd85afcffc16b14`.

The later documentation checkpoint does not change these payloads. The exact
application commit above was activated by the operator.

## Living-portrait validation

Both workflows passed on that application commit:

- 48 renderer lifecycle checks, including context recovery and image fallback.
- 18 bounded-deployment tests, including all three reviewed baselines, unknown
  source rejection, repeat application and rollback.
- 69 optional voice-command assertions using controlled browser speech services.
- 191 existing opening assertions covering the named greeting and idle behavior.
- 100 premium portrait/layout checks across seven viewport sizes with real WebGL.
- 29 construction/facial-motion checks, including actual eye/mouth pixel changes,
  subtle head movement, pause, cleanup and reduced motion.
- 84 login checks across seven viewport sizes, including fixture POST/CSRF and
  remember, password controls, pointer limits, fallback and history restoration.
  Form and authentication-script hashes remain identical to the reviewed baseline.

Workflow screenshots were inspected for construction, complete portrait,
open/closed eyes, speech deformation and the full login. Local reruns passed all
48 renderer and 18 deployment checks, plus syntax, payload identity, runner pins
and whitespace checks. Local Chromium cannot create its required socket, so
browser results and screenshots come from the successful GitHub jobs.

These results establish the hologram/login workflow. Other repository workflows
have separate failures and are not represented as passing here. Stubbed speech
services do not establish real-device microphone accuracy. The existing named
recording still needs a production-device playback check after sign-in.

## Earlier living-portrait activation receipt

**Production activation is confirmed by the operator-supplied receipt.** On
10 November 2026 the operator applied application commit
`4f6e6985113da853368511e221f6e76473a9cd07` and supplied
`GO_HOLOGRAM_PREMIUM_APPLIED_AND_VERIFIED`: eight files changed, the central
runtime verified, and zero additional Owner runtimes enumerated. The receipt
reports no database, route or voice-worker changes and no desktop rebuild.
Zero additional runtimes does not negate verification of the central runtime;
it does not establish verification of any separate Owner installation.
No remote server command was executed by the assistant.

The release runner and immutable hashes above identify the reviewed payload.
Operator-specific instructions remain in the private task conversation.
The received success marker is `GO_HOLOGRAM_PREMIUM_APPLIED_AND_VERIFIED`.
Reinstallation is not required.

The package backs up changed source, rejects unknown overlapping edits and
rolls back failed verification. Database, routes, the private greeting worker
and Windows installer are unchanged.

The remaining check is on the production device: refresh with `Ctrl+Shift+R`.
Check the new login,
then sign in once to check construction, blinking, subtle head/pointer response
and the existing named greeting with lip movement. Idle should remain silent.
The receipt establishes application/runtime installation; actual browser motion
and audible greeting playback remain awaiting the user's device check.
