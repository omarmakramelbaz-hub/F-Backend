# GO Arabic replies and microphone continuation

The one-click talk control now displays the phrase it recognized and accepts
explicit Egyptian Arabic wrappers such as `افتح لي صفحة العملاء` and
`عدد العملاء عندي كام؟`. Commands still resolve only visible authorized cards.
There is no typed command input.

An available Arabic device voice is preferred. If it is absent, fails, or never
starts, GO requests a private Arabic WAV from the same application. The fallback
uses a pinned local eSpeak NG engine, requires no device voice installation or
provider key, and is a basic synthesized voice rather than a neural voice.
It does not alter the existing named opening greeting or its worker.

The first microphone permission prompt has a separate bounded waiting period.
The 15-second speaking window starts only when recognition actually starts.
Nothing listens on page load, and a closed or cancelled session never restarts
the microphone automatically. A browser permission previously blocked must be
allowed by the user through the site's permission control.

Audio requests are authenticated POSTs with CSRF protection, account throttling,
private temporary files and no-store responses. Speech text is passed to a
bounded local process on stdin, with no shell or provider request. Generated
files are deleted after delivery. Grouped Arabic and Latin digits, signed
decimal amounts and Egyptian-pound abbreviations are normalized for speech.

Closing the panel, hiding the page, switching account, removing a card or
changing its metric/scope cancels pending synthesis and existing playback.
Late responses cannot restart speech. A browser autoplay refusal exposes a
single `تشغيل الرد` button that reuses the existing private audio.

## Deployment

Use `scripts/hologram_arabic/run_root.sh` at verified application commit
`7096a56d0f0e4fc1ac3f29f75a6be1996429969d`, with runner SHA-256
`148e9b79ff80b5445bd76087fa5507ee88429c92ddf484e9da3de7ea302ce7b2`.
It accepts `--check` or `--apply` on the known host. The complete workflow
receipts and installer/manifest pins are in
[hologram-premium-status.md](hologram-premium-status.md#arabic-reply-continuation).
The package upgrades either the operator-confirmed living release
`4f6e6985113da853368511e221f6e76473a9cd07` or the one-click release
`7258fd82706494020aa9c4262d41a1ce354ed442`; running the earlier microphone
command first is unnecessary. Unknown overlapping source is refused.

The installer changes only eight bounded application paths and adds an isolated
speech library/helper outside the public directory. It does not install system
or Python packages, change the database, rebuild the desktop program, or replace
the portrait, login form, opening greeting or its worker. It backs up source and
route caches, verifies real Arabic synthesis as the application UID, checks each
enumerated runtime, and rolls back source on verification failure. The engine
archive identity and helper/manifest hashes are pinned. Engine ABI compatibility
is tested on the actual host before source publication.

The older premium package is kept consistent with its current frontend source;
its visual-only installer does not install the Arabic server route or engine.
Use the Arabic package above for this continuation.

Production activation remains pending until the operator supplies a successful
receipt. Browser tests stub speech recognition and playback APIs; they establish
lifecycle behavior, while real recognition, audible output and voice quality
still need the production device. Real engine tests verify a non-silent WAV.

## Validation

- Local: manifest identity, both deployment baselines, rollback/concurrent edits,
  real isolated Arabic synthesis, numeric semantics and renderer lifecycle.
- GitHub workflow: actual PHP subprocess behavior and authenticated Laravel HTTP
  requests, CSRF rejection, audio cleanup and throttling.
- Browser workflow: commands, permissions, native/fallback speech, autoplay replay,
  cancellation, late responses, and existing portrait/login regression checks.

The final release evidence is recorded in `hologram-premium-status.md`.
