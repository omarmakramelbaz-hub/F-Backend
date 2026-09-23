# GO Partner email verification

Phone is a login/contact identifier. These endpoints verify **email ownership**, never phone ownership. They do not send SMS.

## Client flow

1. `POST /api/partner-auth/email/request`: `purpose` (`application`, `activation`, `password_reset`), `mobile`, and `email` for a new application only.
2. `POST /api/partner-auth/email/verify`: `challenge_id`, six-digit `code`. Returns a short-lived `email_verification_token`.
3. Submit the token with the matching phone to `/api/partner-applications`, `/api/partner-applications/activate`, or `/api/partner-auth/password/reset`.

Applications now require `email` and a verification token, including calls from older clients. Approval is still required before activation. The account password is set after approval and a fresh activation email challenge. Passwords require 8–72 characters and confirmation.

Recovery/activation resolve the destination on the server; client-supplied email is ignored. Unknown, unapproved, already activated, or legacy accounts without a verified mailbox receive the same generic challenge response, with no outgoing message. Existing logged-in accounts remain usable. Support must verify identity before enrolling a recovery mailbox for a legacy account; there is no phone-only recovery fallback.

## Server configuration

- Run `php artisan migrate --path=database/migrations/2026_09_22_000001_add_partner_verified_email.php --force`, then clear cached configuration. The existing GO schema bootstrap also runs this migration before partner routes, including requests without scope headers.
- The `partner_smtp` mailer reuses `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, and `MAIL_FROM_ADDRESS`. TLS certificate verification is enabled. Set an authorized sender address; recipients can use Gmail or another email provider.
- `PARTNER_MAIL_MAILER` can select another configured delivering transport. `array` and `log` are rejected outside tests. No credentials belong in source control.
- Use a persistent cache supporting atomic locks. The current single-server file cache is supported; multiple API servers must share a lock-capable Redis cache. Keep a stable, secret `APP_KEY`.

Codes expire after 10 minutes, have five attempts, and are invalidated by successful verification or replacement. Proofs expire after 10 minutes, bind the purpose/phone/mailbox/subject, and are burned before a database transaction. The UI must request a new proof after a failed final submission. Requests are rate-limited by phone, destination and IP; resend has a 60-second cooldown. Cache stores HMACs of codes and hashes of proof tokens, not raw secrets.

`users.partner_auth_email` is excluded from generic mass assignment and serialization. Updating a contact email does not change the recovery mailbox. Tests fake outgoing mail; no real messages are sent. SMTP acceptance and inbox delivery still require a deployment-side mail check with an authorized test recipient.

## Validation

`vendor/bin/phpunit tests/Feature/PartnerEmailAuthTest.php` covers code expiry/reuse/attempts, resend, proof binding, approval, legacy bypass rejection, destination selection, scope separation and mail failure. The isolated CI job reproduces the existing Laravel 8 dependencies, including their existing advisories; it does not upgrade or relax production dependency settings.
